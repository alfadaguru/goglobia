<?php
// ============================================================================
// FILE: app/lib/ai/gia_channel_render.php
// Rich-reply rendering for GIA messaging channels. ONE normalized internal
// message shape -> three platform renderers (WhatsApp interactive, Telegram
// inline keyboard, Messenger quick-replies + generic-template carousel), each
// clamped to that platform's HARD limits (verified against each API's docs):
//
//   WhatsApp : reply buttons <=3 (title <=20 chars) | list <=10 rows (title<=24)
//   Telegram : inline_keyboard rows of {text, callback_data<=64 bytes}
//   Messenger: quick_replies <=13 | generic carousel <=10 elements, <=3 btns/el,
//              title/subtitle <=80 chars
//
// Normalized shape produced by the spine:
//   [
//     'text'    => string,                      // always present
//     'buttons' => [ ['id'=>string,'title'=>string], ... ],   // quick choices
//     'cards'   => [ ['title'=>,'subtitle'=>,'image'=>,'buttons'=>[['id'=>,'title'=>]]], ... ],
//   ]
// A renderer that can't express something degrades to plain text — it NEVER
// sends a payload the platform would reject. Pure transform: no network, no DB.
// ============================================================================

if (!function_exists('gia_render_text_fallback')) {
    /**
     * Flatten a normalized rich message to PLAIN TEXT with the choices listed.
     * Used where a channel/mode can't render native buttons reliably (notably
     * Green API WhatsApp, whose interactive buttons are beta/unstable). Choices
     * are numbered so the traveler can reply with the number or the label text —
     * which the agent understands as a normal message (no callback needed).
     */
    function gia_render_text_fallback(array $norm): string
    {
        $lines = [];
        if (($norm['text'] ?? '') !== '') { $lines[] = $norm['text']; }
        $n = 0;
        foreach (($norm['buttons'] ?? []) as $b) {
            $lines[] = (++$n) . '. ' . $b['title'];
        }
        foreach (($norm['cards'] ?? []) as $c) {
            $head = trim(($c['title'] ?? '') . (($c['subtitle'] ?? '') !== '' ? ' — ' . $c['subtitle'] : ''));
            if ($head !== '') { $lines[] = "\n" . $head; }
            foreach (($c['buttons'] ?? []) as $b) {
                $lines[] = (++$n) . '. ' . $b['title'];
            }
        }
        if ($n > 0) { $lines[] = "\nReply with the number or name of your choice."; }
        return trim(implode("\n", $lines));
    }
}

if (!function_exists('gia_md_to_whatsapp')) {
    /**
     * Convert the Markdown the LLM tends to emit (the web widget renders it via
     * renderMarkdown) into WhatsApp's native formatting, so replies arrive clean
     * on WhatsApp instead of showing literal ** and - markers.
     *
     * WhatsApp text formatting: *bold*  _italic_  ~strikethrough~  ```mono```.
     * Mapping applied:
     *   **bold** / __bold__      -> *bold*
     *   *italic* / _italic_      -> _italic_   (single-asterisk emphasis -> underscore)
     *   # Heading                -> *Heading*  (headings become bold, hashes dropped)
     *   - / * / + bullet         -> • bullet
     *   [label](url)             -> label (url)
     *   `code`                   -> ```code```
     *   | table | rows |         -> stripped to plain lines (WhatsApp has no tables)
     * Applied ONCE to the final outgoing string at the send points — never to the
     * web widget (which keeps real Markdown). Pure transform: no network, no DB.
     */
    function gia_md_to_whatsapp(string $text): string
    {
        if (trim($text) === '') { return $text; }

        // Normalise newlines.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Fenced code blocks ```lang\n...``` -> keep as ``` ... ``` (WhatsApp mono).
        $text = preg_replace('/```[a-zA-Z0-9]*\n?/', "```", $text);

        // Inline code `x` -> ```x``` (WhatsApp uses triple backtick for mono; a
        // single backtick renders literally). Avoid touching fenced blocks (handled).
        $text = preg_replace_callback('/(?<!`)`([^`\n]+)`(?!`)/', static function ($m) {
            return '```' . $m[1] . '```';
        }, $text);

        // Links [label](url) -> "label (url)".
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '$1 ($2)', $text);

        // Bold **x** / __x__ -> WhatsApp *x*. Convert to a placeholder first so the
        // single-asterisk ITALIC pass below cannot re-match the bold output (which
        // would turn *bold* into _bold_). Placeholder uses control chars unlikely
        // to appear in chat text; restored after italics.
        $BOLD_L = "\x01"; $BOLD_R = "\x02";
        $text = preg_replace('/\*\*([^*\n]+)\*\*/', $BOLD_L . '$1' . $BOLD_R, $text);
        $text = preg_replace('/__([^_\n]+)__/', $BOLD_L . '$1' . $BOLD_R, $text);

        // Italic: single *x* -> _x_ (real emphasis only — leading/trailing word
        // boundaries avoid touching bullets, which are handled line-by-line below).
        $text = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?![\*\w])/', '_$1_', $text);

        // Restore bold placeholders as WhatsApp *bold*.
        $text = str_replace([$BOLD_L, $BOLD_R], '*', $text);

        // Process line-by-line for headings, bullets and table rows.
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $trim = ltrim($line);

            // Markdown table separator row (|---|---|) -> drop entirely.
            if (preg_match('/^\|?\s*:?-{2,}.*$/', $trim) && strpos($trim, '-') !== false && (strpos($trim, '|') !== false)) {
                continue;
            }
            // Table data row | a | b | -> "a  b" (pipes/tables unsupported on WA).
            if (strlen($trim) > 1 && $trim[0] === '|') {
                $cells = array_map('trim', array_filter(explode('|', $trim), static fn($c) => trim($c) !== ''));
                $out[] = implode('  ', $cells);
                continue;
            }
            // Heading "# ..." / "## ..." -> bold line.
            if (preg_match('/^#{1,6}\s+(.*)$/', $trim, $m)) {
                $h = trim($m[1]);
                $out[] = $h !== '' ? '*' . $h . '*' : '';
                continue;
            }
            // Bullet "- " / "* " / "+ " -> "• ". Preserve leading indent.
            if (preg_match('/^([-*+])\s+(.*)$/', $trim, $m)) {
                $indent = substr($line, 0, strlen($line) - strlen($trim));
                $out[] = $indent . '• ' . $m[2];
                continue;
            }
            $out[] = $line;
        }
        $text = implode("\n", $out);

        // Collapse 3+ blank lines to a single blank line.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}

if (!function_exists('gia_render_clip')) {
    /** UTF-8-safe truncate to $max chars (no ellipsis bloat beyond the cap). */
    function gia_render_clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($max <= 0) { return ''; }
        if (mb_strlen($s) <= $max) { return $s; }
        return mb_substr($s, 0, $max);
    }
}

if (!function_exists('gia_render_norm')) {
    /** Coerce arbitrary input into the normalized shape with safe defaults. */
    function gia_render_norm($rich): array
    {
        if (is_string($rich)) { return ['text' => $rich, 'buttons' => [], 'cards' => []]; }
        if (!is_array($rich)) { return ['text' => '', 'buttons' => [], 'cards' => []]; }
        $text = (string) ($rich['text'] ?? '');
        $buttons = [];
        foreach ((array) ($rich['buttons'] ?? []) as $b) {
            if (!is_array($b)) { continue; }
            $id = (string) ($b['id'] ?? '');
            $title = (string) ($b['title'] ?? '');
            if ($id === '' || $title === '') { continue; }
            $btn = ['id' => $id, 'title' => $title];
            // A URL button opens a link (used for the hosted pay-link) rather than
            // firing a callback. Only https URLs are carried.
            $u = (string) ($b['url'] ?? '');
            if ($u !== '' && preg_match('#^https://#i', $u)) { $btn['url'] = $u; }
            $buttons[] = $btn;
        }
        $cards = [];
        foreach ((array) ($rich['cards'] ?? []) as $c) {
            if (!is_array($c)) { continue; }
            $cb = [];
            foreach ((array) ($c['buttons'] ?? []) as $b) {
                if (!is_array($b)) { continue; }
                $id = (string) ($b['id'] ?? '');
                $title = (string) ($b['title'] ?? '');
                if ($id === '' || $title === '') { continue; }
                $cb[] = ['id' => $id, 'title' => $title];
            }
            $cards[] = [
                'title'    => (string) ($c['title'] ?? ''),
                'subtitle' => (string) ($c['subtitle'] ?? ''),
                'image'    => (string) ($c['image'] ?? ''),
                'buttons'  => $cb,
            ];
        }
        return ['text' => $text, 'buttons' => $buttons, 'cards' => $cards];
    }
}

// ---------------------------------------------------------------------------
// TELEGRAM — sendMessage body with inline_keyboard. Cards have no native
// carousel, so a card becomes a text block + its buttons as keyboard rows.
// callback_data must be <=64 bytes; we send the choice id (clamped).
// ---------------------------------------------------------------------------
if (!function_exists('gia_render_telegram')) {
    function gia_render_telegram(array $norm, string $chatId): array
    {
        $lines = [];
        if ($norm['text'] !== '') { $lines[] = $norm['text']; }
        $rows = [];
        // Top-level quick buttons: one per row (reliable across clients). A button
        // with a url opens the link (pay-link); others fire a callback.
        foreach ($norm['buttons'] as $b) {
            if (!empty($b['url'])) {
                $rows[] = [['text' => gia_render_clip($b['title'], 64), 'url' => (string) $b['url']]];
            } else {
                $rows[] = [['text' => gia_render_clip($b['title'], 64), 'callback_data' => substr($b['id'], 0, 64)]];
            }
        }
        // Cards: append a titled text block + each card's buttons as rows.
        foreach ($norm['cards'] as $c) {
            $block = trim($c['title'] . ($c['subtitle'] !== '' ? "\n" . $c['subtitle'] : ''));
            if ($block !== '') { $lines[] = "\n" . $block; }
            foreach ($c['buttons'] as $b) {
                $rows[] = [['text' => gia_render_clip($b['title'], 64), 'callback_data' => substr($b['id'], 0, 64)]];
            }
        }
        $body = ['chat_id' => $chatId, 'text' => trim(implode("\n", $lines)) ?: '…'];
        if ($rows !== []) {
            $body['reply_markup'] = ['inline_keyboard' => $rows];
        }
        return $body;
    }
}

// ---------------------------------------------------------------------------
// WHATSAPP — Cloud API interactive. Prefer reply-buttons (<=3). If more choices,
// use a list (<=10 rows). Cards/images aren't expressible as one interactive, so
// we flatten card buttons into the choice set and keep text. Returns the
// 'interactive' sub-object (caller wraps with messaging_product/to), or null to
// signal "send as plain text" (no actionable choices).
// ---------------------------------------------------------------------------
if (!function_exists('gia_render_whatsapp_interactive')) {
    function gia_render_whatsapp_interactive(array $norm): ?array
    {
        // Gather all choices (top-level + card buttons), dedupe by id, keep order.
        // A card's choice carries the card's TITLE + SUBTITLE as its label/description
        // so a WhatsApp list row shows the real fare detail (airline/time/stops/price),
        // not just the generic button text ("Select this fare").
        $choices = [];
        $seen = [];
        $push = function ($b, $desc = '', $label = '') use (&$choices, &$seen) {
            $id = (string) ($b['id'] ?? ''); $t = (string) ($b['title'] ?? '');
            if ($id === '' || $t === '' || isset($seen[$id])) { return; }
            $seen[$id] = true;
            $choices[] = ['id' => $id, 'title' => $label !== '' ? $label : $t, 'description' => $desc];
        };
        foreach ($norm['buttons'] as $b) { $push($b); }
        foreach ($norm['cards'] as $c) {
            $cardLabel = (string) ($c['title'] ?? '');
            $cardDesc  = (string) ($c['subtitle'] ?? '');
            foreach ($c['buttons'] as $b) { $push($b, $cardDesc, $cardLabel); }
        }
        if ($choices === []) { return null; }

        $bodyText = $norm['text'] !== '' ? gia_render_clip($norm['text'], 1024) : 'Please choose:';

        if (count($choices) <= 3) {
            $buttons = [];
            foreach (array_slice($choices, 0, 3) as $c) {
                $buttons[] = ['type' => 'reply', 'reply' => [
                    'id'    => substr($c['id'], 0, 256),
                    'title' => gia_render_clip($c['title'], 20), // WA button title cap
                ]];
            }
            return ['type' => 'button', 'body' => ['text' => $bodyText], 'action' => ['buttons' => $buttons]];
        }

        // >3 choices -> list (max 10 rows). WA list rows support a `description`
        // (<=72 chars) under the title — use it to show the fare detail + price so a
        // row reads "Air Peace 06:30 · LOS → ABV" / "Nonstop · 1h 10m · NGN 119,216".
        $rows = [];
        foreach (array_slice($choices, 0, 10) as $c) {
            $row = [
                'id'    => substr($c['id'], 0, 200),
                'title' => gia_render_clip($c['title'], 24), // WA list row title cap
            ];
            $desc = gia_render_clip((string) ($c['description'] ?? ''), 72);
            if ($desc !== '') { $row['description'] = $desc; }
            $rows[] = $row;
        }
        return [
            'type'   => 'list',
            'body'   => ['text' => $bodyText],
            'action' => [
                'button'   => 'Choose',
                'sections' => [['title' => 'Options', 'rows' => $rows]],
            ],
        ];
    }
}

// ---------------------------------------------------------------------------
// MESSENGER — quick_replies (<=13) for a flat choice set; generic-template
// carousel (<=10 elements, <=3 buttons each, title/subtitle <=80) when there are
// cards. Returns the 'message' object for the Send API, or null for plain text.
// ---------------------------------------------------------------------------
if (!function_exists('gia_render_messenger_message')) {
    function gia_render_messenger_message(array $norm): ?array
    {
        $text = $norm['text'] !== '' ? $norm['text'] : 'Please choose:';

        // Cards -> generic template carousel.
        if ($norm['cards'] !== []) {
            $elements = [];
            foreach (array_slice($norm['cards'], 0, 10) as $c) {
                $el = ['title' => gia_render_clip($c['title'] !== '' ? $c['title'] : $text, 80)];
                if ($c['subtitle'] !== '') { $el['subtitle'] = gia_render_clip($c['subtitle'], 80); }
                if ($c['image'] !== '' && preg_match('#^https://#i', $c['image'])) { $el['image_url'] = $c['image']; }
                $btns = [];
                foreach (array_slice($c['buttons'], 0, 3) as $b) {
                    $btns[] = ['type' => 'postback', 'title' => gia_render_clip($b['title'], 20), 'payload' => substr($b['id'], 0, 1000)];
                }
                if ($btns !== []) { $el['buttons'] = $btns; }
                $elements[] = $el;
            }
            if ($elements !== []) {
                return ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'generic', 'elements' => $elements]]];
            }
        }

        // Flat buttons -> quick replies (<=13).
        if ($norm['buttons'] !== []) {
            $qrs = [];
            foreach (array_slice($norm['buttons'], 0, 13) as $b) {
                $qrs[] = ['content_type' => 'text', 'title' => gia_render_clip($b['title'], 20), 'payload' => substr($b['id'], 0, 1000)];
            }
            return ['text' => gia_render_clip($text, 640), 'quick_replies' => $qrs];
        }

        return null; // no choices -> caller sends plain text
    }
}
