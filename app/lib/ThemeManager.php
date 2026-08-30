<?php
// app/lib/ThemeManager.php

class ThemeManager {
    private static $themesPath = __DIR__ . '/../themes/';
    private static $activeFile = 'active.json';

    /**
     * Get the active theme name
     */
    public static function getActiveTheme() {
        $activeFile = self::$themesPath . self::$activeFile;
        if (!file_exists($activeFile)) {
            return 'default';
        }

        $data = json_decode(file_get_contents($activeFile), true);
        return $data['active_theme'] ?? 'default';
    }

    /**
     * Set active theme
     */
    public static function setActiveTheme($themeName) {
        $themeFile = self::$themesPath . $themeName . '.json';
        if (!file_exists($themeFile)) {
            throw new Exception("Theme '{$themeName}' not found");
        }

        $activeFile = self::$themesPath . self::$activeFile;
        $data = ['active_theme' => $themeName];

        return file_put_contents($activeFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get all available themes
     */
    public static function getAllThemes() {
        $themes = [];
        $activeTheme = self::getActiveTheme();

        $files = glob(self::$themesPath . '*.json');
        foreach ($files as $file) {
            $filename = basename($file, '.json');
            if ($filename === 'active') continue;

            $themeData = json_decode(file_get_contents($file), true);
            $themes[] = [
                'id' => $filename,
                'name' => $themeData['name'] ?? ucfirst($filename),
                'is_active' => $filename === $activeTheme
            ];
        }

        return $themes;
    }

    /**
     * Load theme configuration
     */
    public static function loadTheme($themeName = null) {
        if ($themeName === null) {
            $themeName = self::getActiveTheme();
        }

        $themeFile = self::$themesPath . $themeName . '.json';
        if (!file_exists($themeFile)) {
            $themeFile = self::$themesPath . 'default.json';
        }

        return json_decode(file_get_contents($themeFile), true);
    }

    /**
     * Save theme configuration
     */
    public static function saveTheme($themeName, $config) {
        $themeFile = self::$themesPath . $themeName . '.json';
        return file_put_contents($themeFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Create new theme from existing
     */
    public static function createTheme($newName, $basedOn = 'default') {
        // Validate theme name
        if (!preg_match('/^[a-z0-9-]+$/', $newName)) {
            throw new Exception("Theme name must contain only lowercase letters, numbers, and hyphens");
        }

        $newFile = self::$themesPath . $newName . '.json';
        if (file_exists($newFile)) {
            throw new Exception("Theme '{$newName}' already exists");
        }

        // Load base theme
        $baseTheme = self::loadTheme($basedOn);
        $baseTheme['name'] = ucfirst(str_replace('-', ' ', $newName));

        // Save new theme
        return self::saveTheme($newName, $baseTheme);
    }

    /**
     * Delete theme
     */
    public static function deleteTheme($themeName) {
        if ($themeName === 'default') {
            throw new Exception("Cannot delete default theme");
        }

        $themeFile = self::$themesPath . $themeName . '.json';
        if (!file_exists($themeFile)) {
            throw new Exception("Theme '{$themeName}' not found");
        }

        // If deleting active theme, switch to default
        if (self::getActiveTheme() === $themeName) {
            self::setActiveTheme('default');
        }

        return unlink($themeFile);
    }

    /**
     * Reset theme to default values
     */
    public static function resetTheme($themeName) {
        if ($themeName !== 'default') {
            throw new Exception("Only the default theme can be reset");
        }

        $defaultConfig = [
            "typography" => [
                "font_family" => "Inter",
                "font_size_base" => "14",
                "line_height" => "1.5"
            ],
            "header" => [
                "height" => "64",
                "padding_x" => "16",
                "border_width" => "1",
                "font_size" => "14",
                "background" => "#ffffff",
                "text_color" => "#1f2937",
                "link_color" => "#717c8e",
                "link_hover_color" => "#2563eb",
                "border_color" => "#e5e7eb"
            ],
            "footer" => [
                "padding_y" => "30",
                "padding_x" => "16",
                "border_width" => "1",
                "font_size" => "14",
                "background" => "#ffffff",
                "text_color" => "#475467",
                "heading_color" => "#1d2939",
                "link_color" => "#2563eb",
                "border_color" => "#e4e7ec"
            ],
            "button" => [
                "height" => "40",
                "padding_x" => "16",
                "padding_y" => "8",
                "font_size" => "14",
                "font_weight" => "500",
                "border_radius" => "8",
                "border_width" => "0",
                "background" => "#0058e6",
                "background_hover" => "#104bcb",
                "text_color" => "#ffffff",
                "border_color" => "#0052d6"
            ],
            "input" => [
                "height" => "40",
                "padding_x" => "12",
                "padding_y" => "8",
                "font_size" => "14",
                "border_radius" => "8",
                "border_width" => "1",
                "background" => "#ffffff",
                "text_color" => "#1f2937",
                "border_color" => "#e5e7eb",
                "border_focus_color" => "#3b82f6",
                "placeholder_color" => "#9ca3af"
            ],
            "textarea" => [
                "min_height" => "80",
                "padding_x" => "12",
                "padding_y" => "8",
                "font_size" => "14",
                "border_radius" => "8",
                "border_width" => "1",
                "background" => "#ffffff",
                "text_color" => "#1f2937",
                "border_color" => "#e5e7eb",
                "border_focus_color" => "#3b82f6",
                "placeholder_color" => "#9ca3af"
            ],
            "select" => [
                "height" => "40",
                "padding_x" => "12",
                "padding_y" => "8",
                "font_size" => "14",
                "border_radius" => "8",
                "border_width" => "1",
                "background" => "#ffffff",
                "text_color" => "#1f2937",
                "border_color" => "#e5e7eb",
                "border_focus_color" => "#3b82f6"
            ],
            "card" => [
                "padding" => "20",
                "border_radius" => "12",
                "border_width" => "1",
                "background" => "#ffffff",
                "border_color" => "#e5e7eb",
                "shadow" => "0 1px 3px 0 rgb(0 0 0 / 0.1)"
            ],
            "checkbox" => [
                "size" => "16",
                "border_radius" => "4",
                "border_width" => "1",
                "background" => "#ffffff",
                "background_checked" => "#3b82f6",
                "border_color" => "#e5e7eb",
                "border_checked_color" => "#3b82f6",
                "checkmark_color" => "#ffffff"
            ],
            "radio" => [
                "size" => "16",
                "border_width" => "1",
                "background" => "#ffffff",
                "border_color" => "#e5e7eb",
                "border_checked_color" => "#3b82f6",
                "dot_color" => "#3b82f6"
            ],
            "colors" => [
                "primary" => "#3b82f6",
                "secondary" => "#6b7280",
                "success" => "#10b981",
                "danger" => "#ef4444",
                "warning" => "#f59e0b",
                "info" => "#06b6d4",
                "text" => "#1f2937",
                "text_muted" => "#6b7280",
                "background" => "#ffffff",
                "background_muted" => "#f9fafb",
                "border" => "#e5e7eb"
            ],
            "name" => "Default Theme"
        ];

        return self::saveTheme($themeName, $defaultConfig);
    }

    /**
     * Get available font families
     */
    public static function getAvailableFonts() {
        return [
            'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat','Lexend',
            'Poppins', 'Raleway', 'Nunito', 'Playfair Display', 'Merriweather',
            'Source Sans Pro', 'PT Sans', 'Ubuntu', 'Oswald', 'Work Sans',
            'Rubik', 'DM Sans', 'Manrope', 'Space Grotesk', 'Plus Jakarta Sans', 'Outfit'
        ];
    }
}
