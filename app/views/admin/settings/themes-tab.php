            <!-- Themes Tab -->
            <div id="tab-themes" class="tab-content" style="display: none;">
                <?php
                // Load active theme and all available themes
                require_once __DIR__ . '/../../../lib/ThemeManager.php';
                $activeThemeName = ThemeManager::getActiveTheme();
                $allThemes = ThemeManager::getAllThemes();
                $currentTheme = ThemeManager::loadTheme($activeThemeName);
                $availableFonts = ThemeManager::getAvailableFonts();
                ?>

                <!-- Theme Manager Section -->
                <div class="section mt-5">
                    <div class="flex flex-col sm:flex-row gap-3 items-center justify-between mb-4">
                        <div class="flex items-center">
                            <span class="material-symbols-outlined text-blue-600 mr-2">palette</span>
                            <h3 class="text-lg font-semibold">Theme Manager</h3>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="createNewTheme()" class="btn">
                                <span class="material-symbols-outlined text-base">add</span>
                                New Theme
                            </button>
                            <?php if ($activeThemeName === 'default'): ?>
                            <button type="button" onclick="resetTheme()" class="btn" style="background-color: #f59e0b; color: white;">
                                <span class="material-symbols-outlined text-base">restart_alt</span>
                                Reset
                            </button>
                            <?php else: ?>
                            <button type="button" onclick="deleteTheme()" class="btn rose">
                                <span class="material-symbols-outlined text-base">delete</span>
                                Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-control">
                        <label class="text-sm font-medium">Active Theme</label>
                        <select name="switch_theme" id="themeSelector" class="select" onchange="switchTheme(this.value)">
                            <?php foreach ($allThemes as $theme): ?>
                                <option value="<?= $theme['id'] ?>" <?= $theme['is_active'] ? 'selected' : '' ?>>
                                    <?= $theme['name'] ?> <?= $theme['is_active'] ? '(Active)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Select a theme to customize or set as active</p>
                    </div>
                </div>

                <!-- Typography Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-purple-600 mr-2 text-xl">text_fields</span>
                        <h3 class="text-md font-semibold">Typography</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- FONT SELECTION HIDDEN FOR NOW — SITE USES THE STATIC LOCAL Outfit FONT
                             (assets/fonts + app.css). RE-ENABLE BY REMOVING display:none. -->
                        <div class="form-control" style="display:none">
                            <label class="text-xs">Font Family</label>
                            <select name="theme[typography][font_family]" class="select">
                                <?php foreach ($availableFonts as $font): ?>
                                    <option value="<?= $font ?>" <?= ($currentTheme['typography']['font_family'] ?? 'Inter') === $font ? 'selected' : '' ?>>
                                        <?= $font ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Base Font Size (px)</label>
                            <input type="number" name="theme[typography][font_size_base]" value="<?= $currentTheme['typography']['font_size_base'] ?? '16' ?>" class="input" min="12" max="24">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Line Height</label>
                            <input type="text" name="theme[typography][line_height]" value="<?= $currentTheme['typography']['line_height'] ?? '1.5' ?>" class="input" placeholder="1.5">
                        </div>
                    </div>
                </div>

                <!-- Header Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-blue-600 mr-2 text-xl">web_asset</span>
                        <h3 class="text-md font-semibold">Header</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Height (px)</label>
                            <input type="number" name="theme[header][height]" value="<?= $currentTheme['header']['height'] ?? '64' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding X (px)</label>
                            <input type="number" name="theme[header][padding_x]" value="<?= $currentTheme['header']['padding_x'] ?? '16' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[header][border_width]" value="<?= $currentTheme['header']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Size (px)</label>
                            <input type="number" name="theme[header][font_size]" value="<?= $currentTheme['header']['font_size'] ?? '14' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[header][background]" value="<?= $currentTheme['header']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Color</label>
                            <input type="color" name="theme[header][text_color]" value="<?= $currentTheme['header']['text_color'] ?? '#1f2937' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Link Color</label>
                            <input type="color" name="theme[header][link_color]" value="<?= $currentTheme['header']['link_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Link Hover</label>
                            <input type="color" name="theme[header][link_hover_color]" value="<?= $currentTheme['header']['link_hover_color'] ?? '#2563eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[header][border_color]" value="<?= $currentTheme['header']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Footer Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-gray-600 mr-2 text-xl">web</span>
                        <h3 class="text-md font-semibold">Footer</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Padding Y (px)</label>
                            <input type="number" name="theme[footer][padding_y]" value="<?= $currentTheme['footer']['padding_y'] ?? '48' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding X (px)</label>
                            <input type="number" name="theme[footer][padding_x]" value="<?= $currentTheme['footer']['padding_x'] ?? '16' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[footer][border_width]" value="<?= $currentTheme['footer']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Size (px)</label>
                            <input type="number" name="theme[footer][font_size]" value="<?= $currentTheme['footer']['font_size'] ?? '14' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[footer][background]" value="<?= $currentTheme['footer']['background'] ?? '#1f2937' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Color</label>
                            <input type="color" name="theme[footer][text_color]" value="<?= $currentTheme['footer'][' text_color'] ?? '#9ca3af' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Heading Color</label>
                            <input type="color" name="theme[footer][heading_color]" value="<?= $currentTheme['footer']['heading_color'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Link Color</label>
                            <input type="color" name="theme[footer][link_color]" value="<?= $currentTheme['footer']['link_color'] ?? '#60a5fa' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[footer][border_color]" value="<?= $currentTheme['footer']['border_color'] ?? '#374151' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Button Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-indigo-600 mr-2 text-xl">smart_button</span>
                        <h3 class="text-md font-semibold">Button</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Height (px)</label>
                            <input type="number" name="theme[button][height]" value="<?= $currentTheme['button']['height'] ?? '40' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding X (px)</label>
                            <input type="number" name="theme[button][padding_x]" value="<?= $currentTheme['button']['padding_x'] ?? '16' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding Y (px)</label>
                            <input type="number" name="theme[button][padding_y]" value="<?= $currentTheme['button']['padding_y'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Size (px)</label>
                            <input type="number" name="theme[button][font_size]" value="<?= $currentTheme['button']['font_size'] ?? '14' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Weight</label>
                            <input type="number" name="theme[button][font_weight]" value="<?= $currentTheme['button']['font_weight'] ?? '500' ?>" class="input" min="100" max="900" step="100">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Radius (px)</label>
                            <input type="number" name="theme[button][border_radius]" value="<?= $currentTheme['button']['border_radius'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[button][border_width]" value="<?= $currentTheme['button']['border_width'] ?? '0' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[button][background]" value="<?= $currentTheme['button']['background'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background Hover</label>
                            <input type="color" name="theme[button][background_hover]" value="<?= $currentTheme['button']['background_hover'] ?? '#2563eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Color</label>
                            <input type="color" name="theme[button][text_color]" value="<?= $currentTheme['button']['text_color'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[button][border_color]" value="<?= $currentTheme['button']['border_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Input Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-green-600 mr-2 text-xl">input</span>
                        <h3 class="text-md font-semibold">Input</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Height (px)</label>
                            <input type="number" name="theme[input][height]" value="<?= $currentTheme['input']['height'] ?? '40' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding X (px)</label>
                            <input type="number" name="theme[input][padding_x]" value="<?= $currentTheme['input']['padding_x'] ?? '12' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding Y (px)</label>
                            <input type="number" name="theme[input][padding_y]" value="<?= $currentTheme['input']['padding_y'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Size (px)</label>
                            <input type="number" name="theme[input][font_size]" value="<?= $currentTheme['input']['font_size'] ?? '14' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Radius (px)</label>
                            <input type="number" name="theme[input][border_radius]" value="<?= $currentTheme['input']['border_radius'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[input][border_width]" value="<?= $currentTheme['input']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[input][background]" value="<?= $currentTheme['input']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Color</label>
                            <input type="color" name="theme[input][text_color]" value="<?= $currentTheme['input']['text_color'] ?? '#1f2937' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[input][border_color]" value="<?= $currentTheme['input']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Focus Border</label>
                            <input type="color" name="theme[input][border_focus_color]" value="<?= $currentTheme['input']['border_focus_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Placeholder</label>
                            <input type="color" name="theme[input][placeholder_color]" value="<?= $currentTheme['input']['placeholder_color'] ?? '#9ca3af' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Textarea Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-orange-600 mr-2 text-xl">text_snippet</span>
                        <h3 class="text-md font-semibold">Textarea</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Min Height (px)</label>
                            <input type="number" name="theme[textarea][min_height]" value="<?= $currentTheme['textarea']['min_height'] ?? '80' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding X (px)</label>
                            <input type="number" name="theme[textarea][padding_x]" value="<?= $currentTheme['textarea']['padding_x'] ?? '12' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding Y (px)</label>
                            <input type="number" name="theme[textarea][padding_y]" value="<?= $currentTheme['textarea']['padding_y'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Size (px)</label>
                            <input type="number" name="theme[textarea][font_size]" value="<?= $currentTheme['textarea']['font_size'] ?? '14' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Radius (px)</label>
                            <input type="number" name="theme[textarea][border_radius]" value="<?= $currentTheme['textarea']['border_radius'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[textarea][border_width]" value="<?= $currentTheme['textarea']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[textarea][background]" value="<?= $currentTheme['textarea']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Color</label>
                            <input type="color" name="theme[textarea][text_color]" value="<?= $currentTheme['textarea']['text_color'] ?? '#1f2937' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[textarea][border_color]" value="<?= $currentTheme['textarea']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Focus Border</label>
                            <input type="color" name="theme[textarea][border_focus_color]" value="<?= $currentTheme['textarea']['border_focus_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Placeholder</label>
                            <input type="color" name="theme[textarea][placeholder_color]" value="<?= $currentTheme['textarea']['placeholder_color'] ?? '#9ca3af' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Select Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-teal-600 mr-2 text-xl">arrow_drop_down_circle</span>
                        <h3 class="text-md font-semibold">Select</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Height (px)</label>
                            <input type="number" name="theme[select][height]" value="<?= $currentTheme['select']['height'] ?? '40' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding X (px)</label>
                            <input type="number" name="theme[select][padding_x]" value="<?= $currentTheme['select']['padding_x'] ?? '12' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Padding Y (px)</label>
                            <input type="number" name="theme[select][padding_y]" value="<?= $currentTheme['select']['padding_y'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Font Size (px)</label>
                            <input type="number" name="theme[select][font_size]" value="<?= $currentTheme['select']['font_size'] ?? '14' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Radius (px)</label>
                            <input type="number" name="theme[select][border_radius]" value="<?= $currentTheme['select']['border_radius'] ?? '8' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[select][border_width]" value="<?= $currentTheme['select']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[select][background]" value="<?= $currentTheme['select']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Color</label>
                            <input type="color" name="theme[select][text_color]" value="<?= $currentTheme['select']['text_color'] ?? '#1f2937' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[select][border_color]" value="<?= $currentTheme['select']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Focus Border</label>
                            <input type="color" name="theme[select][border_focus_color]" value="<?= $currentTheme['select']['border_focus_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Card Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-pink-600 mr-2 text-xl">crop_portrait</span>
                        <h3 class="text-md font-semibold">Card</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Padding (px)</label>
                            <input type="number" name="theme[card][padding]" value="<?= $currentTheme['card']['padding'] ?? '20' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Radius (px)</label>
                            <input type="number" name="theme[card][border_radius]" value="<?= $currentTheme['card']['border_radius'] ?? '12' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[card][border_width]" value="<?= $currentTheme['card']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[card][background]" value="<?= $currentTheme['card']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[card][border_color]" value="<?= $currentTheme['card']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Checkbox Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-blue-600 mr-2 text-xl">check_box</span>
                        <h3 class="text-md font-semibold">Checkbox</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Size (px)</label>
                            <input type="number" name="theme[checkbox][size]" value="<?= $currentTheme['checkbox']['size'] ?? '16' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Radius (px)</label>
                            <input type="number" name="theme[checkbox][border_radius]" value="<?= $currentTheme['checkbox']['border_radius'] ?? '4' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[checkbox][border_width]" value="<?= $currentTheme['checkbox']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[checkbox][background]" value="<?= $currentTheme['checkbox']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background Checked</label>
                            <input type="color" name="theme[checkbox][background_checked]" value="<?= $currentTheme['checkbox']['background_checked'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[checkbox][border_color]" value="<?= $currentTheme['checkbox']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Checked</label>
                            <input type="color" name="theme[checkbox][border_checked_color]" value="<?= $currentTheme['checkbox']['border_checked_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Checkmark Color</label>
                            <input type="color" name="theme[checkbox][checkmark_color]" value="<?= $currentTheme['checkbox']['checkmark_color'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Radio Section -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-purple-600 mr-2 text-xl">radio_button_checked</span>
                        <h3 class="text-md font-semibold">Radio</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Size (px)</label>
                            <input type="number" name="theme[radio][size]" value="<?= $currentTheme['radio']['size'] ?? '16' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Width (px)</label>
                            <input type="number" name="theme[radio][border_width]" value="<?= $currentTheme['radio']['border_width'] ?? '1' ?>" class="input">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[radio][background]" value="<?= $currentTheme['radio']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Color</label>
                            <input type="color" name="theme[radio][border_color]" value="<?= $currentTheme['radio']['border_color'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border Checked</label>
                            <input type="color" name="theme[radio][border_checked_color]" value="<?= $currentTheme['radio']['border_checked_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Dot Color</label>
                            <input type="color" name="theme[radio][dot_color]" value="<?= $currentTheme['radio']['dot_color'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Theme Colors -->
                <div class="section mt-5">
                    <div class="flex items-center mb-3">
                        <span class="material-symbols-outlined text-red-600 mr-2 text-xl">palette</span>
                        <h3 class="text-md font-semibold">Theme Colors</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="form-control">
                            <label class="text-xs">Primary</label>
                            <input type="color" name="theme[colors][primary]" value="<?= $currentTheme['colors']['primary'] ?? '#3b82f6' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Secondary</label>
                            <input type="color" name="theme[colors][secondary]" value="<?= $currentTheme['colors']['secondary'] ?? '#6b7280' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Success</label>
                            <input type="color" name="theme[colors][success]" value="<?= $currentTheme['colors']['success'] ?? '#10b981' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Danger</label>
                            <input type="color" name="theme[colors][danger]" value="<?= $currentTheme['colors']['danger'] ?? '#ef4444' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Warning</label>
                            <input type="color" name="theme[colors][warning]" value="<?= $currentTheme['colors']['warning'] ?? '#f59e0b' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Info</label>
                            <input type="color" name="theme[colors][info]" value="<?= $currentTheme['colors']['info'] ?? '#06b6d4' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text</label>
                            <input type="color" name="theme[colors][text]" value="<?= $currentTheme['colors']['text'] ?? '#1f2937' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Text Muted</label>
                            <input type="color" name="theme[colors][text_muted]" value="<?= $currentTheme['colors']['text_muted'] ?? '#6b7280' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background</label>
                            <input type="color" name="theme[colors][background]" value="<?= $currentTheme['colors']['background'] ?? '#ffffff' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Background Muted</label>
                            <input type="color" name="theme[colors][background_muted]" value="<?= $currentTheme['colors']['background_muted'] ?? '#f9fafb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                        <div class="form-control">
                            <label class="text-xs">Border</label>
                            <input type="color" name="theme[colors][border]" value="<?= $currentTheme['colors']['border'] ?? '#e5e7eb' ?>" class="w-full h-10 border rounded cursor-pointer">
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="my-4 flex justify-start gap-3">
                    <input type="hidden" name="active_theme" value="<?= $activeThemeName ?>">
                    <button type="submit" name="save_theme" value="1" form="settings-form" class="btn bg-blue-600 hover:bg-blue-700 text-white">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span class="font-medium">Save Theme</span>
                    </button>
                </div>

                <script>
                function switchTheme(themeName) {
                    if (confirm('Switch to "' + themeName + '" theme? This will reload the page.')) {
                        location.href = '<?= root . admin ?>/settings?switch_theme=' + themeName;
                    } else {
                        location.reload();
                    }
                }

                function createNewTheme() {
                    const name = prompt('Enter new theme name (lowercase, use hyphens):');
                    if (!name) return;

                    if (!/^[a-z0-9-]+$/.test(name)) {
                        alert('Theme name must contain only lowercase letters, numbers, and hyphens');
                        return;
                    }

                    const basedOn = document.getElementById('themeSelector').value;
                    location.href = '<?= root . admin ?>/settings?create_theme=' + name + '&based_on=' + basedOn;
                }

                function deleteTheme() {
                    const themeName = document.getElementById('themeSelector').value;
                    if (themeName === 'default') {
                        alert('Cannot delete default theme!');
                        return;
                    }

                    if (confirm(`Delete theme "${themeName}"? This cannot be undone.`)) {
                        location.href = '<?= root . admin ?>/settings?delete_theme=' + themeName;
                    }
                }
                
                function resetTheme() {
                    const themeName = document.getElementById('themeSelector').value;
                    if (themeName !== 'default') {
                        alert('Only the default theme can be reset!');
                        return;
                    }

                    if (confirm('Reset default theme to original values? All customizations will be lost.')) {
                        location.href = '<?= root . admin ?>/settings?reset_theme=' + themeName;
                    }
                }
                </script>
            </div>

