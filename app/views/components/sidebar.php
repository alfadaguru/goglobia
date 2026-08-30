<?php
// Define the sidebar menu structure
$sidebarMenus = [
    'dashboard' => [
        'title' => 'Dashboard',
        'url' => 'dashboard',
        'icon' => 'dashboard',
        'section' => 'dashboard'
    ],
    'feedback' => [
        'title' => 'Feedback',
        'items' => [
            [
                'title' => 'Alert Messages',
                'url' => 'alerts',
                'icon' => 'error'
            ],
            [
                'title' => 'Notifications',
                'url' => 'notifications',
                'icon' => 'notifications'
            ],
            [
                'title' => 'Badges',
                'url' => 'badges',
                'icon' => 'label'
            ],
            [
                'title' => 'Progress',
                'url' => 'progress',
                'icon' => 'progress_activity'
            ]
        ]
    ],
    'input' => [
        'title' => 'Input',
        'items' => [
            [
                'title' => 'Input Fields',
                'url' => 'input',
                'icon' => 'input'
            ],
            [
                'title' => 'Select Fields',
                'url' => 'select',
                'icon' => 'arrow_drop_down_circle'
            ],
            [
                'title' => 'Ajax Search',
                'url' => 'ajax-search',
                'icon' => 'travel_explore'
            ],
            [
                'title' => 'Textarea Fields',
                'url' => 'textarea',
                'icon' => 'text_fields'
            ],
            [
                'title' => 'Checkbox Fields',
                'url' => 'checkbox',
                'icon' => 'check_box'
            ],
            [
                'title' => 'Radio Fields',
                'url' => 'radio',
                'icon' => 'radio_button_checked'
            ],
            [
                'title' => 'Switch Fields',
                'url' => 'switch',
                'icon' => 'toggle_on'
            ],
            [
                'title' => 'Datepicker Fields',
                'url' => 'datepicker',
                'icon' => 'calendar_today'
            ],
            [
                'title' => 'Buttons',
                'url' => 'buttons',
                'icon' => 'smart_button'
            ]
        ]
    ],
    'layout' => [
        'title' => 'Layout',
        'items' => [
            [
                'title' => 'Cards',
                'url' => 'cards',
                'icon' => 'view_agenda'
            ],
            [
                'title' => 'Modals',
                'url' => 'modals',
                'icon' => 'web_asset'
            ],
            [
                'title' => 'Tabs',
                'url' => 'tabs',
                'icon' => 'tab'
            ],
            [
                'title' => 'Accordion',
                'url' => 'accordion',
                'icon' => 'expand_more'
            ],
            [
                'title' => 'Links',
                'url' => 'links',
                'icon' => 'link'
            ]
        ]
    ],
    'data' => [
        'title' => 'Data',
        'items' => [
            [
                'title' => 'Tables',
                'url' => 'tables',
                'icon' => 'table'
            ],
            [
                'title' => 'Dropdowns',
                'url' => 'dropdowns',
                'icon' => 'arrow_drop_down'
            ]
        ]
    ],
    'overlay' => [
        'title' => 'Overlay',
        'items' => [
            [
                'title' => 'Tooltips',
                'url' => 'tooltips',
                'icon' => 'help'
            ]
        ]
    ],
    'travels' => [
        'title' => 'Travel',
        'items' => [
            [
                'title' => 'Flights',
                'url' => 'flights',
                'icon' => 'flight_takeoff'
            ],
            [
                'title' => 'Hotels',
                'url' => 'hotels',
                'icon' => 'hotel'
            ],
            [
                'title' => 'Cars',
                'url' => 'cars',
                'icon' => ''
            ],
            [
                'title' => 'Tours',
                'url' => 'tours',
                'icon' => 'tour'
            ],
        ]
    ],
];

// Helper function to determine if a URL is active
function isActive($path) {
    return strpos($_SERVER['REQUEST_URI'], $path) !== false;
}

// Helper function to determine which section is active
function getActiveSection() {
    global $sidebarMenus;

    // Check if $sidebarMenus is valid
    if (!is_array($sidebarMenus) || empty($sidebarMenus)) {
        return '';
    }

    foreach ($sidebarMenus as $sectionKey => $section) {
        if (isset($section['url']) && isActive('/'.$section['url'])) {
            return $sectionKey;
        } elseif (isset($section['items']) && is_array($section['items'])) {
            foreach ($section['items'] as $item) {
                if (isset($item['url']) && isActive('/'.$item['url'])) {
                    return $sectionKey;
                }
            }
        }
    }

    return '';
}

if (strpos($_SERVER['REQUEST_URI'], '/components/') !== false): ?>
<!-- HTML to Code Converter - Must run BEFORE PrismJS -->
<script>
// Pre-process HTML in code blocks before PrismJS runs
(function() {
    'use strict';

    function processCodeBlocks() {
        console.log('Pre-processing code blocks before PrismJS...');
        var codeElements = document.querySelectorAll('code');
        console.log('Found', codeElements.length, 'code elements to process');

        for (var i = 0; i < codeElements.length; i++) {
            var codeElement = codeElements[i];

            // Skip if already processed
            if (codeElement.hasAttribute('data-html-converted')) {
                continue;
            }

            // Check if it has HTML child elements (real HTML, not text)
            var children = codeElement.children;
            if (children.length > 0) {
                var htmlContent = '';

                // Extract HTML from all child elements
                for (var j = 0; j < children.length; j++) {
                    htmlContent += children[j].outerHTML;
                }

                if (htmlContent.trim()) {
                    console.log('Converting HTML to text for code block', i, ':', htmlContent.substring(0, 100) + '...');

                    // Clear and set as text content (this escapes HTML automatically)
                    codeElement.innerHTML = '';
                    codeElement.textContent = htmlContent;

                    // Add HTML language class
                    if (!codeElement.className.includes('language-')) {
                        codeElement.className += ' language-html';
                    }

                    // Mark as processed
                    codeElement.setAttribute('data-html-converted', 'true');

                    console.log('Successfully converted code block', i);
                }
            }
        }
        console.log('Pre-processing complete');
    }

    // Run immediately if DOM is already loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', processCodeBlocks);
    } else {
        processCodeBlocks();
    }

    // Make available globally for testing
    window.processCodeBlocks = processCodeBlocks;
})();
</script>

<!-- Default PrismJS Setup with Line Numbers and Copy Button -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/toolbar/prism-toolbar.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/copy-to-clipboard/prism-copy-to-clipboard.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Add line numbers to all pre elements
  document.querySelectorAll('pre').forEach(function(pre) {
    pre.classList.add('line-numbers');
  });

  if (typeof Prism !== 'undefined') {
    Prism.highlightAll();
  }
});
</script>
<?php endif; ?>

<?php
$sidebarMenusJson = json_encode($sidebarMenus, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
?>

<!-- Modern Compact Sidebar with AlpineJS -->
<div class="w-64 min-w-64 max-w-64 flex-shrink-0 bg-white shadow-xs h-screen sticky top-0 overflow-visible border-r border-gray-100">
    <div class="h-full flex flex-col">
        <!-- Scrollable Navigation with Custom Scrollbar -->
        <div class="flex-1 overflow-y-auto overflow-x-hidden sidebar-scroll">
            <nav class="" x-data="{
                activeSection: '<?= strpos($_SERVER['REQUEST_URI'], '/') !== false ? explode('/', trim($_SERVER['REQUEST_URI'], '/'))[1] : '' ?>',
                activeMenu: '',
                openMenus: {
                    feedback: false,
                    input: false,
                    layout: false,
                    data: false,
                    overlay: false
                },
                menuItems: <?= htmlspecialchars($sidebarMenusJson, ENT_QUOTES, 'UTF-8') ?>,
                searchQuery: '',
                init() {
                    // Detect which menu should be active based on current URL
                    const currentUrl = window.location.pathname;

                    // Check each menu section
                    if (currentUrl.includes('/alerts') || currentUrl.includes('/notifications') || currentUrl.includes('/badges') || currentUrl.includes('/progress')) {
                        this.activeMenu = 'feedback';
                    } else if (currentUrl.includes('/input') || currentUrl.includes('/select') || currentUrl.includes('/textarea') || currentUrl.includes('/checkbox') || currentUrl.includes('/radio') || currentUrl.includes('/switch') || currentUrl.includes('/datepicker') || currentUrl.includes('/buttons')) {
                        this.activeMenu = 'input';
                    } else if (currentUrl.includes('/cards') || currentUrl.includes('/modals') || currentUrl.includes('/tabs') || currentUrl.includes('/accordion')) {
                        this.activeMenu = 'layout';
                    } else if (currentUrl.includes('/tables') || currentUrl.includes('/dropdowns')) {
                        this.activeMenu = 'data';
                    } else if (currentUrl.includes('/tooltips')) {
                        this.activeMenu = 'overlay';
                    }

                    // Close all menus first
                    Object.keys(this.openMenus).forEach(key => {
                        this.openMenus[key] = false;
                    });

                    // Open only the active menu section
                    if (this.activeMenu && this.openMenus.hasOwnProperty(this.activeMenu)) {
                        this.openMenus[this.activeMenu] = true;
                    }
                },
                isMenuOpen(key) {
                    if (this.searchQuery) {
                        const section = this.menuItems[key];
                        if (section && section.items && Array.isArray(section.items)) {
                            const query = this.searchQuery.toLowerCase();
                            for (const item of section.items) {
                                if (item.title && item.title.toLowerCase().includes(query)) return true;
                            }
                        }
                    }
                    return this.openMenus[key] === true;
                },
                sectionMatches(key) {
                    if (!this.searchQuery) return true;
                    const section = this.menuItems[key];
                    if (!section) return false;
                    if (section.title && section.title.toLowerCase().includes(this.searchQuery.toLowerCase())) return true;
                    if (section.items && Array.isArray(section.items)) {
                        const query = this.searchQuery.toLowerCase();
                        for (const item of section.items) {
                            if (item.title && item.title.toLowerCase().includes(query)) return true;
                        }
                    }
                    return false;
                },
                itemMatches(item, sectionKey) {
                    if (!this.searchQuery) return true;
                    if (!item) return false;
                    const query = this.searchQuery.toLowerCase();
                    const section = this.menuItems[sectionKey];
                    if (section && section.title && section.title.toLowerCase().includes(query)) return true;
                    return !!(item.title && item.title.toLowerCase().includes(query));
                }
            }">
                <!-- Search Bar -->
                <div class="px-4 py-2.5 border-b border-gray-100">
                    <div class="relative flex items-center">
                        <span class="material-symbols-outlined text-[18px] text-gray-400 absolute left-2.5 pointer-events-none">search</span>
                        <input type="text" 
                               x-model="searchQuery" 
                               placeholder="Search components..." 
                               class="w-full bg-gray-50 border border-gray-200 rounded-md text-[12px] text-gray-700 pl-8 pr-7 py-1.5 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors placeholder:text-gray-400"
                        />
                        <button x-show="searchQuery" @click="searchQuery = ''" class="absolute right-2.5 text-gray-400 hover:text-gray-600 flex items-center">
                            <span class="material-symbols-outlined text-[15px]">close</span>
                        </button>
                    </div>
                </div>

                <!-- Dashboard -->
                <a href="<?= root ?>components/dashboard" class="sidebar-item group flex items-center px-4 py-2.5 mx-0 text-base font-medium rounded-none hover:pl-5 <?= isActive('/'.$sidebarMenus['dashboard']['url']) ? 'text-blue-600 bg-blue-50 border-l-2 border-blue-600 active-item' : 'text-gray-700 hover:bg-gray-100 border-l-2 border-transparent' ?> transition-all duration-150" x-show="!searchQuery || 'dashboard'.includes(searchQuery.toLowerCase())">
                    <span class="material-symbols-outlined text-[20px] mr-3 <?= isActive('/'.$sidebarMenus['dashboard']['url']) ? 'text-blue-600' : 'text-gray-500 group-hover:text-gray-700' ?>"><?= $sidebarMenus['dashboard']['icon'] ?></span>
                    <span class="truncate"><?= $sidebarMenus['dashboard']['title'] ?></span>
                </a>

                <?php foreach ($sidebarMenus as $sectionKey => $section): ?>
                <?php if ($sectionKey === 'dashboard') continue; // Dashboard is rendered separately ?>

                <!-- <?= $section['title'] ?> Section -->
                <div class="<?= $sectionKey === 'feedback' ? 'mt-0' : 'mt-0' ?>" x-show="sectionMatches('<?= $sectionKey ?>')">
                    <button @click="openMenus.<?= $sectionKey ?> = !openMenus.<?= $sectionKey ?>" class="w-full flex items-center justify-between px-4 py-2 mx-0 text-xs font-bold tracking-wider uppercase text-gray-600 hover:text-gray-800 hover:bg-gray-50 <?= $sectionKey === 'feedback' ? 'border-y' : 'border-b' ?> border-gray-100 transition-colors">
                        <span><?= $section['title'] ?></span>
                        <span class="material-symbols-outlined text-[16px]" :class="isMenuOpen('<?= $sectionKey ?>') ? 'rotate-180' : ''">
                            expand_more
                        </span>
                    </button>
                    <div x-show="isMenuOpen('<?= $sectionKey ?>')" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform scale-95" x-transition:enter-end="opacity-100 transform scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 transform scale-100" x-transition:leave-end="opacity-0 transform scale-95" class="bg-gray-50">
                        <?php foreach ($section['items'] as $itemindex => $item): ?>
                        <a href="<?= root ?>components/<?= $item['url'] ?>" class="sidebar-item group flex items-center px-10 py-2 mx-0 mb-0 text-sm font-medium border-l-2 <?= isActive('/'.$item['url']) ? 'text-blue-600 bg-white border-blue-600 active-item' : 'text-gray-700 hover:bg-gray-100 border-transparent hover:border-gray-300' ?> transition-all duration-150" x-show="itemMatches(menuItems['<?= $sectionKey ?>'].items[<?= $itemindex ?>], '<?= $sectionKey ?>')">
                            <span class="material-symbols-outlined text-[18px] mr-3 <?= isActive('/'.$item['url']) ? 'text-blue-600' : 'text-gray-500 group-hover:text-gray-700' ?>"><?= $item['icon'] ?></span>
                            <span class="truncate"><?= $item['title'] ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
</div>

<style>
/* Custom Scrollbar for Sidebar */
.sidebar-scroll {
    scrollbar-width: thin;
    scrollbar-color: #CBD5E1 #F1F5F9;
}

.sidebar-scroll::-webkit-scrollbar {
    width: 4px;
}

.sidebar-scroll::-webkit-scrollbar-track {
    background: #F9FAFB;
}

.sidebar-scroll::-webkit-scrollbar-thumb {
    background-color: #CBD5E1;
    border-radius: 20px;
}

.sidebar-scroll::-webkit-scrollbar-thumb:hover {
    background-color: #94A3B8;
}

/* Sidebar item hover effect */
.sidebar-item {
    position: relative;
    overflow: hidden;
}

.sidebar-item::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: 0;
    height: 100%;
    width: 0;
    background-color: rgba(59, 130, 246, 0.08);
    transition: width 0.2s ease;
    z-index: -1;
}

.sidebar-item:hover::after {
    width: 100%;
}

/* Active state border style */
.sidebar-item.active-item {
    border-left-width: 2px;
}

/* Rounded borders for PrismJS code blocks */
pre[class*="language-"] {
    border-radius: 0.5rem !important;
    border: 1px solid #e5e7eb;
    overflow: hidden;
}

pre[class*="language-"] code {
    border-radius: 0.5rem !important;
}

/* Line numbers styling with rounded corners */
.line-numbers .line-numbers-rows {
    border-radius: 0.5rem 0 0 0.5rem;
}

/* Toolbar styling */
.code-toolbar {
    border-radius: 0.5rem;
}

.code-toolbar .toolbar {
    border-radius: 0 0.5rem 0.5rem 0;
}

pre[class*="language-"],
code[class*="language-"] {
	color: #9cdcfe;
	font-size: 14px;
	text-shadow: none;
	font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace;
	direction: ltr;
	text-align: left;
	white-space: pre;
	word-spacing: normal;
	word-break: normal;
	line-height: 1.5;
	-moz-tab-size: 4;
	-o-tab-size: 4;
	tab-size: 4;
	-webkit-hyphens: none;
	-moz-hyphens: none;
	-ms-hyphens: none;
	hyphens: none;
}

pre[class*="language-"]::selection,
code[class*="language-"]::selection {
	text-shadow: none;
	background: #b3d4fc;
}

@media print {
	pre[class*="language-"],
	code[class*="language-"] {
		text-shadow: none;
	}
}

pre[class*="language-"] {
	padding: 1em;
	margin: .5em 0;
	overflow: auto;
	background-color: #1e1e1e;
}

:not(pre) > code[class*="language-"] {
	padding: .1em .3em;
	border-radius: .3em;
	color: #db4c69;
	background: #f9f2f4;
}


/*********************************************************
* Tokens
*/
.namespace {
	opacity: .7;
}

.token.comment,
.token.prolog,
.token.doctype,
.token.cdata {
	color: #6a9955;
}

.token.punctuation {
	color: #d4d4d4;
}

.token.property,
.token.tag,
.token.boolean,
.token.number,
.token.constant,
.token.symbol,
.token.deleted {
	color: #b5cea8;
}

.token.selector,
.token.attr-name,
.token.string,
.token.char,
.token.inserted {
	color: #ce9178;
}

.token.operator,
.token.entity,
.token.url,
.language-css .token.string,
.style .token.string {
	color: #d4d4d4;
	background: #1e1e1e;
}

.token.atrule,
.token.attr-value,
.token.keyword {
	color: #569cd6;
}

.token.function {
	color: #dcdcaa;
}

.token.regex,
.token.important,
.token.variable {
	color: #d16969;
}

.token.important,
.token.bold {
	font-weight: bold;
}

.token.italic {
	font-style: italic;
}

.token.constant {
	color: #9CDCFE;
}

.token.class-name,
.token.builtin
{

	color: #4EC9B0;
}

.token.parameter {
	color: #9CDCFE;
}

.token.interpolation {
	color: #9CDCFE;
}

.token.punctuation.interpolation-punctuation {
	color: #569cd6;
}

.token.boolean {
	color: #569cd6;
}

.token.property {
	color: #9cdcfe;
}

.token.selector {
	color: #d7ba7d;
}

.token.tag {
	color: #569cd6;
}

.token.attr-name {
	color: #9cdcfe;
}

.token.attr-value {
	color: #ce9178;
}

.token.entity {
	color: #4ec9b0;
	cursor: unset;
}

.token.namespace {
	color: #4ec9b0;
}
/*********************************************************
* Language Specific
*/
pre[class*="language-javascript"],
code[class*="language-javascript"] {
	color: #4ec9b0;
}

pre[class*="language-css"],
code[class*="language-css"] {
	color: #CE9178;
}

pre[class*="language-html"],
code[class*="language-html"] {
	color: #d4d4d4;
}

.language-html .token.punctuation {
	color: #808080;
}
/*********************************************************
* Line highlighting
*/
pre[data-line] {
	position: relative;
}

pre[class*="language-"] > code[class*="language-"] {
	position: relative;
	z-index: 1;
}

.line-highlight {
	position: absolute;
	left: 0;
	right: 0;
	padding: inherit 0;
	margin-top: 1em;
	background: #f7ebc6;
	box-shadow: inset 5px 0 0 #f7d87c;
	z-index: 0;
	pointer-events: none;
	line-height: inherit;
	white-space: pre;
}

</style>
