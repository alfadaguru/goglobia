<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Textarea Components</h1>
                    <p class="text-gray-600 mb-6">Textarea field components with various features, sizes, and functionality.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Basic Textarea -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Basic Textarea</label>
                                <textarea class="textarea" placeholder="Enter your message here..."></textarea>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Basic Textarea</label>
    <textarea class="textarea" placeholder="Enter your message here..."></textarea>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Textarea with Character Count -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Message with Character Count</label>
                                <div x-data="{ 
                                    message: '', 
                                    maxLength: 200,
                                    get remaining() { return this.maxLength - this.message.length; },
                                    get isNearLimit() { return this.remaining <= 20; },
                                    get isOverLimit() { return this.remaining < 0; }
                                }">
                                    <textarea 
                                        class="textarea" 
                                        placeholder="Type your message..."
                                        x-model="message"
                                        :maxlength="maxLength"
                                    ></textarea>
                                    <div class="flex justify-between items-center mt-1 text-xs">
                                        <span class="text-gray-500">Maximum 200 characters</span>
                                        <span :class="isOverLimit ? 'text-red-600' : (isNearLimit ? 'text-yellow-600' : 'text-gray-500')" 
                                              x-text="message.length + '/' + maxLength"></span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Message with Character Count</label>
    <div x-data="{ 
        message: '', 
        maxLength: 200,
        get remaining() { return this.maxLength - this.message.length; },
        get isNearLimit() { return this.remaining <= 20; },
        get isOverLimit() { return this.remaining < 0; }
    }">
        <textarea 
            class="textarea" 
            placeholder="Type your message..."
            x-model="message"
            :maxlength="maxLength"
        ></textarea>
        <div class="flex justify-between items-center mt-1 text-xs">
            <span class="text-gray-500">Maximum 200 characters</span>
            <span :class="isOverLimit ? 'text-red-600' : (isNearLimit ? 'text-yellow-600' : 'text-gray-500')" 
                  x-text="message.length + '/' + maxLength"></span>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Auto-Expanding Textarea -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Auto-Expanding Textarea</label>
                                <textarea 
                                    class="textarea resize-none overflow-hidden" 
                                    placeholder="This textarea grows as you type..."
                                    x-data
                                    x-init="$el.style.height = $el.scrollHeight + 'px'"
                                    @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                                    rows="2"
                                ></textarea>
                                <div class="text-xs text-gray-500 mt-1">Automatically adjusts height based on content</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Auto-Expanding Textarea</label>
    <textarea 
        class="textarea resize-none overflow-hidden" 
        placeholder="This textarea grows as you type..."
        x-data
        x-init="$el.style.height = $el.scrollHeight + 'px'"
        @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
        rows="2"
    ></textarea>
    <div class="text-xs text-gray-500 mt-1">Automatically adjusts height based on content</div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Textarea with Toolbar -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Textarea with Formatting Toolbar</label>
                                <div x-data="{ 
                                    content: '',
                                    insertText(before, after = '') {
                                        const textarea = this.$refs.textarea;
                                        const start = textarea.selectionStart;
                                        const end = textarea.selectionEnd;
                                        const selectedText = textarea.value.substring(start, end);
                                        const replacement = before + selectedText + after;
                                        
                                        this.content = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
                                        
                                        // Restore cursor position
                                        this.$nextTick(() => {
                                            textarea.focus();
                                            textarea.setSelectionRange(start + before.length, start + before.length + selectedText.length);
                                        });
                                    }
                                }">
                                    <!-- Toolbar -->
                                    <div class="flex flex-wrap gap-1 p-2 bg-gray-50 border border-gray-200 rounded-t-md border-b-0">
                                        <button @click="insertText('**', '**')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">format_bold</span>
                                            Bold
                                        </button>
                                        <button @click="insertText('*', '*')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">format_italic</span>
                                            Italic
                                        </button>
                                        <button @click="insertText('~~', '~~')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">strikethrough_s</span>
                                            Strike
                                        </button>
                                        <div class="border-l border-gray-300 mx-1"></div>
                                        <button @click="insertText('# ')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">title</span>
                                            H1
                                        </button>
                                        <button @click="insertText('## ')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">title</span>
                                            H2
                                        </button>
                                        <button @click="insertText('- ')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">format_list_bulleted</span>
                                            List
                                        </button>
                                        <button @click="insertText('[', '](url)')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">link</span>
                                            Link
                                        </button>
                                        <button @click="insertText('`', '`')" class="btn-sm outline flex items-center gap-1 text-xs">
                                            <span class="material-symbols-outlined text-sm">code</span>
                                            Code
                                        </button>
                                    </div>
                                    
                                    <!-- Textarea -->
                                    <textarea 
                                        x-ref="textarea"
                                        x-model="content"
                                        class="textarea rounded-t-none border-t-0" 
                                        placeholder="Type your markdown content here..."
                                        rows="6"
                                    ></textarea>
                                    
                                    <div class="text-xs text-gray-500 mt-1">Supports basic Markdown formatting</div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Textarea with Formatting Toolbar</label>
    <div x-data="{ 
        content: '',
        insertText(before, after = '') {
            const textarea = this.$refs.textarea;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            const replacement = before + selectedText + after;
            
            this.content = textarea.value.substring(0, start) + replacement + textarea.value.substring(end);
        }
    }">
        <!-- Toolbar -->
        <div class="flex flex-wrap gap-1 p-2 bg-gray-50 border border-gray-200 rounded-t-md border-b-0">
            <button @click="insertText('**', '**')" class="btn-sm outline">Bold</button>
            <button @click="insertText('*', '*')" class="btn-sm outline">Italic</button>
            <button @click="insertText('# ')" class="btn-sm outline">H1</button>
        </div>
        
        <!-- Textarea -->
        <textarea 
            x-ref="textarea"
            x-model="content"
            class="textarea rounded-t-none border-t-0" 
            placeholder="Type your markdown content here..."
            rows="6"
        ></textarea>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Textarea with Live Preview -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Textarea with Live Preview</label>
                                <div x-data="{ 
                                    activeTab: 'write',
                                    content: '# Hello World\\n\\nThis is **bold** text and this is *italic* text.\\n\\n- List item 1\\n- List item 2\\n- List item 3\\n\\n[Link to example](https://example.com)',
                                    get previewHtml() {
                                        return this.content
                                            .replace(/^### (.*$)/gim, '<h3 class=\"text-lg font-semibold mt-4 mb-2\">$1</h3>')
                                            .replace(/^## (.*$)/gim, '<h2 class=\"text-xl font-semibold mt-4 mb-2\">$1</h2>')
                                            .replace(/^# (.*$)/gim, '<h1 class=\"text-2xl font-bold mt-4 mb-2\">$1</h1>')
                                            .replace(/\\*\\*(.*?)\\*\\*/gim, '<strong class=\"font-semibold\">$1</strong>')
                                            .replace(/\\*(.*?)\\*/gim, '<em class=\"italic\">$1</em>')
                                            .replace(/^- (.*$)/gim, '<li class=\"ml-4\">• $1</li>')
                                            .replace(/\\[([^\\]]+)\\]\\(([^\\)]+)\\)/gim, '<a href=\"$2\" class=\"text-blue-600 hover:underline\">$1</a>')
                                            .replace(/`([^`]+)`/gim, '<code class=\"bg-gray-100 px-1 rounded text-sm\">$1</code>')
                                            .replace(/\\n/g, '<br>');
                                    }
                                }">
                                    <!-- Tab Headers -->
                                    <div class="flex border-b border-gray-200 mb-0">
                                        <button @click="activeTab = 'write'" :class="activeTab === 'write' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-600'" class="px-4 py-2 border-b-2 font-medium text-sm transition-colors">
                                            <span class="material-symbols-outlined text-sm mr-1">edit</span>
                                            Write
                                        </button>
                                        <button @click="activeTab = 'preview'" :class="activeTab === 'preview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-600'" class="px-4 py-2 border-b-2 font-medium text-sm transition-colors">
                                            <span class="material-symbols-outlined text-sm mr-1">visibility</span>
                                            Preview
                                        </button>
                                    </div>
                                    
                                    <!-- Write Tab -->
                                    <div x-show="activeTab === 'write'" x-transition>
                                        <textarea 
                                            x-model="content"
                                            class="textarea rounded-t-none border-t-0" 
                                            placeholder="Type your markdown content here..."
                                            rows="8"
                                        ></textarea>
                                    </div>
                                    
                                    <!-- Preview Tab -->
                                    <div x-show="activeTab === 'preview'" x-transition class="border border-gray-200 rounded-b-md p-4 min-h-[200px] bg-white">
                                        <div x-html="previewHtml" class="prose prose-sm max-w-none"></div>
                                        <div x-show="!content.trim()" class="text-gray-400 italic">Nothing to preview</div>
                                    </div>
                                    
                                    <div class="text-xs text-gray-500 mt-1">Supports basic Markdown with live preview</div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Textarea with Live Preview</label>
    <div x-data="{ 
        activeTab: 'write',
        content: '# Hello World\\n\\nThis is **bold** text',
        get previewHtml() {
            return this.content
                .replace(/^# (.*$)/gim, '<h1 class=\"text-2xl font-bold\">$1</h1>')
                .replace(/\\*\\*(.*?)\\*\\*/gim, '<strong>$1</strong>')
                .replace(/\\n/g, '<br>');
        }
    }">
        <!-- Tab Headers -->
        <div class="flex border-b border-gray-200">
            <button @click="activeTab = 'write'" class="px-4 py-2 border-b-2">Write</button>
            <button @click="activeTab = 'preview'" class="px-4 py-2 border-b-2">Preview</button>
        </div>
        
        <!-- Content -->
        <div x-show="activeTab === 'write'">
            <textarea x-model="content" class="textarea" rows="8"></textarea>
        </div>
        <div x-show="activeTab === 'preview'" class="border p-4">
            <div x-html="previewHtml"></div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Small Textarea -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Small Textarea</label>
                                <textarea class="textarea text-sm h-16" placeholder="Small textarea for brief notes..."></textarea>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Small Textarea</label>
    <textarea class="textarea text-sm h-16" placeholder="Small textarea for brief notes..."></textarea>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Large Textarea -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Large Textarea</label>
                                <textarea class="textarea text-base min-h-[120px]" placeholder="Large textarea for detailed content..." rows="6"></textarea>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Large Textarea</label>
    <textarea class="textarea text-base min-h-[120px]" placeholder="Large textarea for detailed content..." rows="6"></textarea>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Textarea with Fixed Height -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Fixed Height (No Resize)</label>
                                <textarea class="textarea resize-none h-24" placeholder="This textarea has a fixed height and cannot be resized..."></textarea>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Fixed Height (No Resize)</label>
    <textarea class="textarea resize-none h-24" placeholder="This textarea has a fixed height and cannot be resized..."></textarea>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Disabled State Textarea -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Disabled State</label>
                                <textarea class="textarea" placeholder="Disabled textarea" disabled>This textarea is disabled and cannot be edited.</textarea>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Disabled State</label>
    <textarea class="textarea" placeholder="Disabled textarea" disabled>This textarea is disabled and cannot be edited.</textarea>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Error State Textarea -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Error State</label>
                                <textarea class="textarea border-red-300 focus-visible:border-red-500" placeholder="Textarea with error">Invalid content that needs to be corrected.</textarea>
                                <div class="text-red-600 text-xs mt-1">This field contains invalid content</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Error State</label>
    <textarea class="textarea border-red-300 focus-visible:border-red-500" placeholder="Textarea with error">Invalid content that needs to be corrected.</textarea>
    <div class="text-red-600 text-xs mt-1">This field contains invalid content</div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Success State Textarea -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Success State</label>
                                <textarea class="textarea border-green-300 focus-visible:border-green-500" placeholder="Valid textarea">This content has been validated and looks good!</textarea>
                                <div class="text-green-600 text-xs mt-1">Content validated successfully</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Success State</label>
    <textarea class="textarea border-green-300 focus-visible:border-green-500" placeholder="Valid textarea">This content has been validated and looks good!</textarea>
    <div class="text-green-600 text-xs mt-1">Content validated successfully</div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Textarea with Word Count -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Comment with Word Count</label>
                                <div x-data="{ 
                                    content: '',
                                    get wordCount() {
                                        return this.content.trim() ? this.content.trim().split(/\\s+/).length : 0;
                                    },
                                    get charCount() {
                                        return this.content.length;
                                    }
                                }">
                                    <textarea 
                                        x-model="content"
                                        class="textarea" 
                                        placeholder="Write your comment here..."
                                        rows="4"
                                    ></textarea>
                                    <div class="flex justify-between items-center mt-1 text-xs text-gray-500">
                                        <span>Express your thoughts clearly</span>
                                        <div class="flex gap-4">
                                            <span x-text="wordCount + ' words'"></span>
                                            <span x-text="charCount + ' characters'"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Comment with Word Count</label>
    <div x-data="{ 
        content: '',
        get wordCount() {
            return this.content.trim() ? this.content.trim().split(/\\s+/).length : 0;
        },
        get charCount() {
            return this.content.length;
        }
    }">
        <textarea 
            x-model="content"
            class="textarea" 
            placeholder="Write your comment here..."
            rows="4"
        ></textarea>
        <div class="flex justify-between items-center mt-1 text-xs text-gray-500">
            <span>Express your thoughts clearly</span>
            <div class="flex gap-4">
                <span x-text="wordCount + ' words'"></span>
                <span x-text="charCount + ' characters'"></span>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>