<?php
// app/lib/html_sanitizer.php
//
// Self-contained allowlist HTML sanitizer for admin-authored rich text that is
// published to the PUBLIC (CMS pages + their translations). Added for the
// admin-CMS audit finding C-01: CMS `content` was echoed raw to every visitor,
// so a `cms:edit` holder (incl. a lower-priv employee) could store <script> /
// onerror= / javascript: and have it run in every visitor's (and admin's)
// browser. The site CSP uses script-src 'unsafe-inline', so it does NOT mitigate
// stored HTML XSS — this sanitizer is the control.
//
// Design: DOM-based (NOT regex — regex HTML sanitizers are notoriously
// bypassable). We parse with DOMDocument, walk the tree, and:
//   - drop any element not on the tag allowlist (unwrapping its safe children),
//   - hard-remove dangerous elements WITH their subtree (script/style/etc.),
//   - strip every attribute not on the allowlist,
//   - strip any href/src whose scheme is not http/https/mailto/tel (kills
//     javascript:, vbscript:, and non-image data: URIs), keeping relative URLs,
//   - strip inline `style` values containing expression()/url(javascript:)/etc.
//
// The allowlist is deliberately broad on FORMATTING (the CKEditor here is
// configured with GeneralHtmlSupport name:/.*/, so real pages use headings,
// lists, links, images, tables, figures, blockquotes, iframes via mediaEmbed).
// We keep those; we only remove the executable/active vectors.

if (!function_exists('cms_sanitize_html')) {

    /** Tags kept (lowercase). Anything else is unwrapped (children kept) unless on the KILL list. */
    function _cms_allowed_tags(): array
    {
        return [
            // structure / text
            'p', 'br', 'hr', 'span', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'sub', 'sup', 'small', 'mark', 'abbr', 'code', 'pre', 'kbd', 'samp', 'var', 'q',
            'blockquote', 'cite',
            // lists
            'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            // links / media
            'a', 'img', 'figure', 'figcaption', 'picture', 'source',
            // tables
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
            // embeds (CKEditor mediaEmbed emits iframe/oembed; keep iframe but
            // scheme-restrict its src below). We keep iframe because the editor
            // supports it by design; on* handlers + bad schemes are still stripped.
            'iframe',
        ];
    }

    /** Elements removed together with their entire subtree (never safe in published content). */
    function _cms_kill_tags(): array
    {
        return ['script', 'style', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option', 'base', 'link', 'meta', 'noscript', 'template', 'svg', 'math', 'frame', 'frameset', 'title', 'head'];
    }

    /** Attributes kept on any element (lowercase). Everything else is dropped. */
    function _cms_allowed_attrs(): array
    {
        return [
            'href', 'src', 'srcset', 'alt', 'title', 'class', 'style', 'id',
            'width', 'height', 'colspan', 'rowspan', 'scope', 'dir', 'lang',
            'target', 'rel', 'loading', 'referrerpolicy', 'allowfullscreen',
            'data-slug', 'data-id', 'start', 'type', 'cite', 'datetime',
        ];
    }

    /** URL attributes whose scheme must be validated. */
    function _cms_url_attrs(): array
    {
        return ['href', 'src'];
    }

    /** True when a URL is safe to keep (relative, or an allowed scheme). */
    function _cms_url_is_safe(string $url): bool
    {
        $u = trim($url);
        if ($u === '') {
            return false;
        }
        // Collapse control chars/whitespace that are used to smuggle schemes
        // (e.g. "java\tscript:"). Browsers ignore these, so we must too.
        $compact = strtolower(preg_replace('/[\x00-\x20]+/', '', $u));
        // Relative URLs, anchors and query strings are safe.
        if ($compact === '' || $compact[0] === '/' || $compact[0] === '#' || $compact[0] === '?') {
            return true;
        }
        if (strpos($compact, ':') === false) {
            // no scheme at all (e.g. "page/about", "foo.html") -> relative, safe.
            return true;
        }
        foreach (['http:', 'https:', 'mailto:', 'tel:'] as $ok) {
            if (strpos($compact, $ok) === 0) {
                return true;
            }
        }
        // Allow inline IMAGE data URIs only (data:image/png;...). Everything else
        // with a data:/javascript:/vbscript: scheme is rejected.
        if (strpos($compact, 'data:image/') === 0) {
            return true;
        }
        return false;
    }

    /** Reject style values carrying active content. */
    function _cms_style_is_safe(string $style): bool
    {
        $s = strtolower($style);
        foreach (['expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '@import', 'url(javascript'] as $bad) {
            if (strpos($s, $bad) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sanitize admin rich-text HTML for safe public output. Returns a cleaned
     * HTML string (formatting preserved, active content removed). Empty input
     * returns ''. On any parser error it FAILS CLOSED to a tag-stripped,
     * entity-encoded plain-text rendering (never returns the raw input).
     */
    function cms_sanitize_html(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }
        if (!class_exists('DOMDocument')) {
            // No DOM extension — fail closed to escaped text (no markup, but safe).
            return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
        }

        $allowedTags  = array_flip(_cms_allowed_tags());
        $killTags     = array_flip(_cms_kill_tags());
        $allowedAttrs = array_flip(_cms_allowed_attrs());
        $urlAttrs     = array_flip(_cms_url_attrs());

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prevErrors = libxml_use_internal_errors(true);
        // Wrap so we can parse a fragment as UTF-8 without DOCTYPE/<html> noise.
        $wrapped = '<?xml encoding="UTF-8"><div id="__cms_root__">' . $html . '</div>';
        $ok = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if (!$ok) {
            // Parse failed — fail closed.
            return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
        }

        $root = $doc->getElementById('__cms_root__');
        if (!$root) {
            // Fallback: operate on the whole document body if the wrapper got lost.
            $root = $doc->documentElement;
            if (!$root) {
                return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
            }
        }

        // Depth-first clean. Collect nodes first (live NodeList mutates as we edit).
        $clean = function (DOMNode $node) use (&$clean, $doc, $allowedTags, $killTags, $allowedAttrs, $urlAttrs) {
            // Snapshot children.
            $children = [];
            foreach ($node->childNodes as $c) {
                $children[] = $c;
            }
            foreach ($children as $child) {
                if ($child instanceof DOMElement) {
                    $tag = strtolower($child->tagName);

                    if (isset($killTags[$tag])) {
                        // Remove element AND its subtree entirely.
                        $node->removeChild($child);
                        continue;
                    }

                    if (!isset($allowedTags[$tag])) {
                        // Not allowed but not dangerous: UNWRAP — keep its (to-be-
                        // cleaned) children, drop the tag itself. Clean the element
                        // first so its own children are sanitized, then splice up.
                        $clean($child);
                        while ($child->firstChild) {
                            $node->insertBefore($child->firstChild, $child);
                        }
                        $node->removeChild($child);
                        continue;
                    }

                    // Allowed tag: scrub attributes.
                    if ($child->hasAttributes()) {
                        $attrs = [];
                        foreach ($child->attributes as $a) {
                            $attrs[] = $a->nodeName;
                        }
                        foreach ($attrs as $attrName) {
                            $lower = strtolower($attrName);
                            // Kill every event handler and anything not allow-listed.
                            if (strpos($lower, 'on') === 0 || !isset($allowedAttrs[$lower])) {
                                $child->removeAttribute($attrName);
                                continue;
                            }
                            $val = $child->getAttribute($attrName);
                            if (isset($urlAttrs[$lower])) {
                                if (!_cms_url_is_safe($val)) {
                                    $child->removeAttribute($attrName);
                                    continue;
                                }
                            }
                            if ($lower === 'srcset') {
                                // srcset is a comma list of URLs — validate each.
                                $okSet = true;
                                foreach (explode(',', $val) as $cand) {
                                    $u = trim(explode(' ', trim($cand))[0] ?? '');
                                    if ($u !== '' && !_cms_url_is_safe($u)) { $okSet = false; break; }
                                }
                                if (!$okSet) { $child->removeAttribute($attrName); continue; }
                            }
                            if ($lower === 'style' && !_cms_style_is_safe($val)) {
                                $child->removeAttribute($attrName);
                                continue;
                            }
                        }
                    }

                    // Harden anchors that open a new tab (reverse-tabnabbing).
                    if ($tag === 'a' && strtolower($child->getAttribute('target')) === '_blank') {
                        $rel = trim($child->getAttribute('rel') . ' noopener noreferrer');
                        $child->setAttribute('rel', trim($rel));
                    }

                    // Recurse into the (now attribute-clean) allowed element.
                    $clean($child);

                } elseif ($child instanceof DOMComment) {
                    // Drop comments (can carry conditional-comment script in old IE,
                    // and are never needed in published content).
                    $node->removeChild($child);
                }
                // DOMText and other nodes: leave as-is (text is inert; it is
                // re-serialized with proper entity encoding by saveHTML).
            }
        };

        $clean($root);

        // Serialize the cleaned children of the wrapper (not the wrapper itself).
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $doc->saveHTML($c);
        }
        return trim($out);
    }
}
