<?php
// ============================================================================
// TRAIN (RAIL) — STATION CATALOG IMPORT
// ============================================================================

@$SECURE or die('Access Denied!');

if (!function_exists('_train_ensure_stations_table')) {
    function _train_ensure_stations_table($db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS `rail_stations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(10) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `name_chinese` VARCHAR(150) NOT NULL,
            `city` VARCHAR(100) NOT NULL,
            `country` VARCHAR(100) NOT NULL,
            `journey_type` INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
}

if (!function_exists('_train_station_counts')) {
    /** @return array{total:int,by_type:array<int,int>} */
    function _train_station_counts($db): array
    {
        _train_ensure_stations_table($db);
        $total = (int)$db->count('rail_stations');
        $byType = [];
        foreach ([1, 2, 3] as $type) {
            $byType[$type] = (int)$db->count('rail_stations', ['journey_type' => $type]);
        }
        return ['total' => $total, 'by_type' => $byType];
    }
}

if (!function_exists('_train_lcr_stations_catalog')) {
    /**
     * LCR Train (老中火车票) supplier station codes — Laos–China railway network.
     *
     * @return list<array{code:string,name:string,name_chinese:string,city:string,country:string,journey_type:int}>
     */
    function _train_lcr_stations_catalog(): array
    {
        $laos = [
            ['LAVCM', 'Nateuy', '纳堆'],
            ['LAVDM', 'Namor', '纳磨'],
            ['LAVOM', 'Vang Vieng', '万荣'],
            ['LAVQM', 'Phon Hong', '蓬洪'],
            ['LAVTM', 'Vientiane', '万象'],
            ['LAVFM', 'Muang Xay', '孟赛'],
            ['LAVHM', 'Muang Nga', '孟阿'],
            ['LAVJM', 'Luang Prabang', '琅勃拉邦'],
            ['LAVBM', 'Boten', '磨丁'],
            ['LAVMM', 'Kasi', '嘎西'],
        ];
        $china = [
            ['CNENM', 'Xishuangbanna', '西双版纳'],
            ['CNMWM', 'Mengla', '勐腊'],
            ['CNKOM', 'Kunming South', '昆明南'],
            ['CNMHM', 'Mohan', '磨憨'],
            ['CNKMM', 'Kunming', '昆明'],
            ['CNPEM', "Pu'er", '普洱'],
            ['CNAXM', 'Yuxi', '玉溪'],
        ];

        $stations = [];
        foreach ($laos as [$code, $name, $nameChinese]) {
            $stations[] = [
                'code'           => $code,
                'name'           => $name,
                'name_chinese'   => $nameChinese,
                'city'           => $name,
                'country'        => 'Laos',
                'journey_type'   => 2,
            ];
        }
        foreach ($china as [$code, $name, $nameChinese]) {
            $stations[] = [
                'code'           => $code,
                'name'           => $name,
                'name_chinese'   => $nameChinese,
                'city'           => $name,
                'country'        => 'China',
                'journey_type'   => 2,
            ];
        }

        return $stations;
    }
}

if (!function_exists('_train_upsert_lcr_stations')) {
    /** Insert or update LCR supplier stations in rail_stations. */
    function _train_upsert_lcr_stations($db): int
    {
        _train_ensure_stations_table($db);

        $count = 0;
        foreach (_train_lcr_stations_catalog() as $station) {
            $existing = $db->get('rail_stations', 'id', ['code' => $station['code']]);
            if ($existing) {
                $db->update('rail_stations', $station, ['code' => $station['code']]);
            } else {
                $db->insert('rail_stations', $station);
            }
            $count++;
        }

        return $count;
    }
}

if (!function_exists('_train_import_stations')) {
    /**
     * Import China (12306), Laos-China, and Whoosh Indonesia stations into rail_stations.
     *
     * @return array{success:bool,message:string,imported:int,total_in_db:int,by_type?:array}
     */
    function _train_import_stations($db): array
    {
        _train_ensure_stations_table($db);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://kyfw.12306.cn/otn/resources/js/framework/station_name.js',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $content = curl_exec($ch);
        curl_close($ch);

        if (empty($content)) {
            throw new RuntimeException('Failed to fetch station catalog from 12306');
        }

        if (!preg_match("/var\s+station_names\s*=\s*'([^']+)'/", $content, $matches)) {
            throw new RuntimeException('Failed to extract stations variable from 12306 catalog');
        }

        $records = explode('@', $matches[1]);

        $db->query("DELETE FROM `rail_stations` WHERE `journey_type` IN (1, 2)");

        $whooshStations = [
            ['code' => 'IDHMA', 'name' => 'Halim', 'name_chinese' => '哈林', 'city' => 'Jakarta (Halim)', 'country' => 'Indonesia', 'journey_type' => 3],
            ['code' => 'IDTLA', 'name' => 'Tegalluar', 'name_chinese' => '德卡鲁尔', 'city' => 'Bandung (Tegalluar)', 'country' => 'Indonesia', 'journey_type' => 3],
            ['code' => 'IDPGA', 'name' => 'Padalarang', 'name_chinese' => '帕达拉朗', 'city' => 'Bandung (Padalarang)', 'country' => 'Indonesia', 'journey_type' => 3],
            ['code' => 'IDKGA', 'name' => 'Karawang', 'name_chinese' => '卡拉旺', 'city' => 'Karawang', 'country' => 'Indonesia', 'journey_type' => 3],
        ];
        foreach ($whooshStations as $ws) {
            if (!$db->get('rail_stations', 'id', ['code' => $ws['code']])) {
                $db->insert('rail_stations', $ws);
            }
        }

        $userMap = [
            'VAP' => 'Beijing North', 'BOP' => 'Beijing East', 'BJP' => 'Beijing',
            'VNP' => 'Beijing South', 'IPP' => 'Beijing Daxing', 'BXP' => 'Beijing West',
            'IFP' => 'Beijing Chaoyang', 'BUI' => 'Beijing Tongzhou', 'CYI' => 'Fangshan East',
            'HIP' => 'Houlvcun', 'LXI' => 'Lixian', 'YMI' => 'Yamenkoudong',
            'CUW' => 'Chongqing North', 'COE' => 'Chongqing East', 'CQW' => 'Chongqing',
            'CRW' => 'Chongqing South', 'CXW' => 'Chongqing West', 'LTU' => 'Liantang',
            'SHH' => 'Shanghai', 'SNH' => 'Shanghai South', 'AOH' => 'Shanghai Hongqiao',
            'SXH' => 'Shanghai West', 'TBP' => 'Tianjin North', 'TJP' => 'Tianjin',
            'TIP' => 'Tianjin South', 'TXP' => 'Tianjin West', 'YTM' => 'Vientiane',
            'ZWT' => 'World Expo', 'LTJ' => 'Camel Lane',
        ];

        $translateEnglishName = static function ($pinyin, $userMap, $code) {
            if (isset($userMap[$code])) {
                return $userMap[$code];
            }
            $pinyin = strtolower(trim($pinyin));
            $suffixes = [
                'hongqiao' => ' Hongqiao', 'daxing' => ' Daxing', 'jichang' => ' Airport',
                'xintang' => ' Xintang', 'bei' => ' North', 'nan' => ' South',
                'xi' => ' West', 'dong' => ' East',
            ];
            foreach ($suffixes as $suffix => $replacement) {
                if (substr($pinyin, -strlen($suffix)) === $suffix) {
                    return ucwords(substr($pinyin, 0, -strlen($suffix))) . $replacement;
                }
            }
            return ucwords($pinyin);
        };

        $count = 0;
        foreach ($records as $record) {
            $record = trim($record);
            if ($record === '') {
                continue;
            }

            $fields = explode('|', $record);
            if (count($fields) < 8) {
                continue;
            }

            $chineseName = trim($fields[1]);
            $code = strtoupper(trim($fields[2]));
            $pinyin = trim($fields[3]);
            $cityName = trim($fields[7] ?? '');

            $isLao = false;
            $countryName = 'China';
            $englishName = '';

            if (isset($fields[8]) && $fields[8] === 'lao') {
                $isLao = true;
                $countryName = 'Laos';
                $englishName = isset($fields[10]) ? ucwords(strtolower(trim($fields[10]))) : '';
            }

            if ($englishName === '') {
                $englishName = $translateEnglishName($pinyin, $userMap, $code);
            }

            $db->insert('rail_stations', [
                'code'           => $code,
                'name'           => $englishName,
                'name_chinese'   => $chineseName,
                'city'           => $cityName !== '' ? $cityName : $englishName,
                'country'        => $countryName,
                'journey_type'   => $isLao ? 2 : 1,
            ]);
            $count++;
        }

        _train_upsert_lcr_stations($db);

        $stats = _train_station_counts($db);

        return [
            'success'      => true,
            'message'      => "Successfully imported {$count} China/Laos stations plus LCR catalog.",
            'imported'     => $count,
            'total_in_db'  => $stats['total'],
            'by_type'      => $stats['by_type'],
        ];
    }
}

if (!function_exists('_train_search_stations')) {
    /**
     * Search rail_stations for autocomplete (web + mobile API).
     *
     * @return array{stations:list<array>,searched_all_types:bool,journey_type:int}
     */
    function _train_search_stations($db, string $query = '', int $journeyType = 3, int $limit = 30): array
    {
        _train_ensure_stations_table($db);

        $query = trim($query);
        $limit = max(1, min(100, $limit));
        $journeyType = max(0, $journeyType);

        $columns = ['code', 'name', 'name_chinese', 'city', 'country', 'journey_type'];

        $buildWhere = static function (string $q, int $typeFilter, int $lim): array {
            $where = ['LIMIT' => $lim];

            if ($q === '') {
                if ($typeFilter > 0) {
                    $where['journey_type'] = $typeFilter;
                }
                return $where;
            }

            $or = [
                'name[~]'         => $q,
                'name_chinese[~]' => $q,
                'code[~]'         => $q,
                'city[~]'         => $q,
                'country[~]'      => $q,
            ];

            if ($typeFilter > 0) {
                $where['AND'] = [
                    'journey_type' => $typeFilter,
                    'OR'           => $or,
                ];
            } else {
                $where['OR'] = $or;
            }

            return $where;
        };

        $mapRows = static function ($rows): array {
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $s) {
                $out[] = [
                    'code'         => $s['code'],
                    'name'         => $s['name'],
                    'name_chinese' => $s['name_chinese'],
                    'city'         => $s['city'],
                    'country'      => $s['country'],
                    'journey_type' => (int)$s['journey_type'],
                ];
            }
            return $out;
        };

        $effectiveType = in_array($journeyType, [1, 2, 3], true) ? $journeyType : 3;
        $rows = $db->select('rail_stations', $columns, $buildWhere($query, $effectiveType, $limit));
        $stations = $mapRows($rows);

        return [
            'stations'           => $stations,
            'searched_all_types' => false,
            'journey_type'       => $effectiveType,
        ];
    }
}

if (!function_exists('_train_station_english_only')) {
    /** Strip Chinese/CJK characters and tidy labels for English-only UI. */
    function _train_station_english_only(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        $label = preg_replace('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{F900}-\x{FAFF}]+/u', '', $label) ?? $label;
        $label = preg_replace('/\(\s*\)/', '', $label) ?? $label;
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label, " \t\n\r\0\x0B-·");
    }
}

if (!function_exists('_train_station_label')) {
    /** Resolve station code to English display name from rail_stations (falls back to code). */
    function _train_station_label($db, string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }

        _train_ensure_stations_table($db);

        $row = $db->get('rail_stations', ['name'], ['code' => $code]);
        if (!is_array($row)) {
            return $code;
        }

        $name = _train_station_english_only(trim((string)($row['name'] ?? '')));

        return $name !== '' ? $name : $code;
    }
}
