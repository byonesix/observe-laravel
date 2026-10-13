<?php

namespace Observe\Laravel;

final class Scrubber
{
    public const REPLACEMENT = '[scrubbed]';

    public const DEFAULT_KEYS = [
        'authorization', 'auth', 'cookie', 'session', 'sessionid', 'sid', 'password', 'passwd',
        'pwd', 'passphrase', 'secret', 'token', 'jwt', 'bearer', 'apikey', 'privatekey', 'signature', 'hmac', 'credential',
        'credentials', 'dsn', 'databaseurl', 'connectionstring', 'cardnumber', 'cardholder', 'cardexpiry', 'cardexp', 'creditcard', 'pan',
        'cc', 'ccnumber', 'ccnum', 'securitycode', 'cvc', 'cvc2', 'cvv', 'cvv2', 'ccv', 'iban', 'accountnumber', 'routingnumber', 'sortcode',
        'bankaccount', 'ssn', 'socialsecuritynumber', 'pin', 'pincode', 'otp', 'authcode', 'authorizationcode',
        'recoverycodes', 'twofactorcode', 'authpw', 'recurringdetailreference',
    ];

    private const QUERY_ONLY_KEYS = ['code' => true];

    private const TERMINAL = ['key' => true, 'credential' => true];

    private const SAFE_SUFFIX = [
        'type' => true, 'status' => true, 'at' => true, 'required' => true, 'consent' => true, 'attempts' => true,
        'count' => true, 'enabled' => true, 'length' => true, 'expires' => true, 'expiry' => true,
    ];

    private const SAFE_PREFIX = ['has' => true, 'is' => true, 'should' => true, 'can' => true, 'needs' => true];

    private const SAFE_KEYS = [
        'idempotencykey' => true, 'cachekey' => true, 'primarykey' => true, 'foreignkey' => true, 'sortkey' => true,
        'partitionkey' => true, 'routekey' => true, 'publickey' => true, 'lockkey' => true, 'uniquekey' => true,
        'objectkey' => true, 'translationkey' => true,
    ];

    private const CARD_EXEMPT_KEYS = [
        'pspreference' => true, 'psp' => true, 'imei' => true, 'imsi' => true, 'iccid' => true, 'ean' => true,
        'gtin' => true, 'barcode' => true, 'sku' => true, 'ordernumber' => true, 'invoicenumber' => true,
        'merchantreference' => true, 'reference' => true, 'timeus' => true, 'startus' => true, 'durationus' => true,
    ];

    private const TOKENS = [
        ['eyJ', '/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{4,4096}\.[A-Za-z0-9_-]{4,4096}\.[A-Za-z0-9_-]{0,4096}/', self::REPLACEMENT],
        ['_live_', '/'.self::TOKEN_START.'(?:sk|rk|pk)_live_[A-Za-z0-9]{6,}/', self::REPLACEMENT],
        ['_test_', '/'.self::TOKEN_START.'(?:sk|rk|pk)_test_[A-Za-z0-9]{6,}/', self::REPLACEMENT],
        ['_', '/'.self::TOKEN_START.'(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})/', self::REPLACEMENT],
        ['xox', '/'.self::TOKEN_START.'xox[abpr]-[A-Za-z0-9-]{10,}/', self::REPLACEMENT],
        ['AKIA', '/'.self::TOKEN_START.'AKIA[0-9A-Z]{16}\b/', self::REPLACEMENT],
        ['AIza', '/'.self::TOKEN_START.'AIza[0-9A-Za-z_-]{20,}/', self::REPLACEMENT],
        ['AQE', '/'.self::TOKEN_START.'AQE[^\s"\'`]{40,}/', self::REPLACEMENT],
        ['v', '/(v[01](?:=|%3D))[A-Za-z0-9]{32,}+/i', '$1'.self::REPLACEMENT],
        ['in', '/'.self::TOKEN_START.'((?i:pin|otp|cvc|cvv|cvv2|cvc2)\b[ \t]{1,4}(?:(?i:code|number|is)[ \t]{1,4})?)\d{3,8}\b/', '$1'.self::REPLACEMENT],
        ['bot', '/'.self::TOKEN_START.'bot(\d{5,}):[A-Za-z0-9_-]{20,}/', 'bot$1:'.self::REPLACEMENT],
        ['jsessionid', '/;jsessionid=[^;?#\/\s"\']+/i', ';jsessionid='.self::REPLACEMENT],
        ['-u', '/(\s(?:-u|--user)\s+[^\s:\'"]+):[^\s\'"]+/', '$1:'.self::REPLACEMENT],
        ['earer', '/'.self::TOKEN_START.'(Bearer)\s++[A-Za-z0-9._~+\/-]{1,4096}+=*+/i', '$1 '.self::REPLACEMENT],
        ['asic', '/'.self::TOKEN_START.'(Basic)\s++[A-Za-z0-9._~+\/-]{1,4096}+=*+/i', '$1 '.self::REPLACEMENT],
    ];

    private const CARD_SEPARATOR = '[ .\/-]|\xC2\xA0|\xE2\x80[\x89\xAF]';

    private const MAX_DEPTH = 12;

    private const MAX_TEXT_DEPTH = 3;

    private const MAX_TEXT_BYTES = 131072;

    private const MAX_CUT_TOKEN_BYTES = 1024;

    private const MAX_ENCODED_BYTES = 8192;

    private const MAX_DECODES = 256;

    private const QUOTED = '"(?:[^"\\\\\n]++|\\\\.)*+(?:"|(?=\r?\n|$))|\'(?:[^\'\\\\\n]++|\\\\.)*+(?:\'|(?=\r?\n|$))|`[^`\n]*+`|\\\\u0022(?:[^"\\\\\n]++|\\\\(?!u0022).)*+(?:\\\\u0022|(?=["\r\n]|$))|\\\\u0027(?:[^"\\\\\n]++|\\\\(?!u0027).)*+(?:\\\\u0027|(?=["\r\n]|$))';

    private const HEX_ESCAPE = 'u00(?:0[9adAD]|2[267]|3[ceCE])';

    private const TOKEN_START = '(?:\b|(?<=\\\\[nrt]|\\\\'.self::HEX_ESCAPE.'))';

    private const VALUE_BACKSLASH = '\\\\(?!u00(?:2[267]|3[ceCE])|(?:[nrt\\\\]|u000[9adAD])[A-Za-z0-9_.\-\[\]]{1,64}["\'`]?[ \t]*+[=:])';

    private const WORD = '(?!\/)(?:[^\s&,;"\'`<>(){}\[\]?=\\\\]++|'.self::VALUE_BACKSLASH.')++';

    private const NEXT_WORD = '[ \t]{1,4}(?![A-Za-z0-9_.\-]{1,64}["\'`]?\s{0,4}[=:])(?:[^\s&,;"\'`<>(){}\[\]?=:\\\\]++|'.self::VALUE_BACKSLASH.')++';

    private readonly array $keys;

    private readonly string $pair;

    private array $verdicts = [];

    private int $decodes = 0;

    public function __construct(array $keys)
    {
        $this->keys = array_fill_keys(array_filter(array_map(self::normalize(...), $keys), fn (string $k) => $k !== ''), true);
        $this->pair = '/(?<![A-Za-z0-9_.\-\[\]])(?<key>[A-Za-z0-9_.\-\[\]]{1,64})'
            .'(?:(?<sep>["\'`]?(?:[ \t]*+(?:=>|=)|\s(?i:like)\s)[ \t]*+)(?<value>'.self::QUOTED.'|'.self::WORD.')'
            .'|(?<csep>["\'`]?[ \t]*+:[ \t]*+(?:\r?\n[ \t]*+)?)(?<cvalue>'.self::QUOTED.'|(?![A-Za-z0-9_.\-\[\]]{1,64}["\'`]?[ \t]*+[=:])'.self::WORD.'(?:'.self::NEXT_WORD.'){0,8}))/';
    }

    public static function withDefaults(array $extra): self
    {
        return new self([...self::DEFAULT_KEYS, ...$extra]);
    }

    public function keyed(array $data, int $depth = 0, ?string $parent = null): array
    {
        foreach ($data as $key => $value) {
            if ($value === null || is_bool($value)) {
                continue;
            }

            $name = is_string($key) ? $key : null;

            if ($name !== null && $this->matchesKey($name, $parent)) {
                $data[$key] = self::REPLACEMENT;
            } elseif (is_array($value)) {
                $data[$key] = $depth < self::MAX_DEPTH ? $this->keyed($value, $depth + 1, $name ?? $parent) : self::REPLACEMENT;
            } elseif (is_string($value)) {
                $data[$key] = $this->text($value, ! $this->cardExempt($name));
            } elseif (is_int($value) && $value >= 1_000_000_000_000 && ! $this->cardExempt($name) && self::luhn((string) $value)) {
                $data[$key] = self::REPLACEMENT;
            }
        }

        return $data;
    }

    public function text(string $value, bool $cards = true, int $depth = 0): string
    {
        if (strlen($value) < 6) {
            return $value;
        }

        if (strlen($value) > self::MAX_TEXT_BYTES) {
            $value = Values::truncate($value, self::MAX_TEXT_BYTES);
            $partialToken = strcspn(strrev(substr($value, -self::MAX_CUT_TOKEN_BYTES)), " \t\r\n");
            $value = Values::truncate($value, strlen($value) - $partialToken).self::REPLACEMENT;
        }

        if ($depth === 0) {
            $this->decodes = 0;
        }

        if (str_contains($value, '\\/')) {
            $value = preg_replace('/(?<!\\\\)\\\\\//', '/', $value) ?? self::REPLACEMENT;
        }

        if (str_contains($value, '#') && preg_match('/^#\d+ /m', $value) === 1) {
            $value = preg_replace('/^(#\d+ [^\r\n(]{0,1024}\(\d+\): [^\r\n(]{1,512})\([^\r\n]*\)[ \t]*$/m', '$1(...)', $value) ?? self::REPLACEMENT;
        }

        $value = $this->urls($value);

        if (str_contains($value, ':') && preg_match('/^[ \t]*(?:[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+|(?i:authorization|cookie|signature))[ \t]*:/m', $value) === 1) {
            $value = preg_replace_callback(
                '/^(?<head>[ \t]*(?<key>[A-Za-z0-9]{1,32}+(?:-[A-Za-z0-9]{1,32}+){1,6}+|(?i:authorization|cookie|signature))[ \t]*:[ \t]*+)(?<value>[^\r\n]+)(?=\r?\n|\z)/m',
                fn (array $m) => $this->matchesKey($m['key']) && preg_match('/^(Bearer|Basic)\b/i', $m['value']) !== 1 ? $m['head'].self::REPLACEMENT : $m[0],
                $value,
            ) ?? self::REPLACEMENT;
        }

        foreach (self::TOKENS as [$needle, $pattern, $replacement]) {
            if (stripos($value, $needle) !== false) {
                $value = preg_replace($pattern, $replacement, $value) ?? self::REPLACEMENT;
            }
        }

        if (stripos($value, 'cookie') !== false) {
            $value = preg_replace_callback('/'.self::TOKEN_START.'((?:set-)?cookie\s*:\s*)((?:[^\r\n\\\\]++|\\\\(?![nr]|u000[adAD]))*+)/i', fn (array $m) => $m[1].(preg_replace('/([^=;\s]+)=([^;\r\n]*)/', '$1='.self::REPLACEMENT, $m[2]) ?? self::REPLACEMENT), $value) ?? self::REPLACEMENT;
        }

        if (str_contains($value, '"]=>')) {
            $value = preg_replace_callback(
                '/\["(?<key>[^"\r\n]{1,64})"\]=>(?<space>\s{0,64})(?:string\(\d+\) "[^"\r\n]*+"|int\(-?\d+\)|float\([^)\r\n]*\))/',
                fn (array $m) => $this->matchesKey($m['key']) ? '["'.$m['key'].'"]=>'.$m['space'].'string(10) "'.self::REPLACEMENT.'"' : $m[0],
                $value,
            ) ?? self::REPLACEMENT;
        }

        if (str_contains($value, 's:') && str_contains($value, '";')) {
            $value = preg_replace_callback(
                '/s:\d{1,9}+:"(?<key>[^"\r\n]{1,64}+)";(?:s:\d{1,9}+:"[^"]{0,8192}+";|i:-?\d{1,20}+;|d:[0-9.eE+-]{1,32}+;)/',
                fn (array $m) => $this->matchesKey($m['key']) ? 's:'.strlen($m['key']).':"'.$m['key'].'";s:10:"'.self::REPLACEMENT.'";' : $m[0],
                $value,
            ) ?? self::REPLACEMENT;
        }

        if (str_contains($value, '"{\\"') || str_contains($value, '"{\\u0022')) {
            $value = $this->encodedJson($value, $depth);
        }

        if (str_contains($value, '[')) {
            $value = $this->secretLists($value);
        }

        if (strpbrk($value, '=:') !== false || stripos($value, ' like ') !== false) {
            $value = $this->pairs($value, $depth);
        }

        if (str_contains($value, ':') && str_contains($value, '=')) {
            $value = $this->colonValuesWithEquals($value);
        }

        if ($depth === 0 && (str_contains($value, '\\"') || stripos($value, '\\u00') !== false)) {
            $ascii = preg_replace_callback('/\\\\u00([2-7][0-9a-f])/i', fn (array $match): string => chr((int) hexdec($match[1])), $value) ?? self::REPLACEMENT;
            $flat = preg_replace('/(?<!\\\\)\\\\++(?=["\'])/', '', $ascii) ?? self::REPLACEMENT;
            $scrubbedFlat = $this->pairs($flat, self::MAX_TEXT_DEPTH);
            $value = $scrubbedFlat === $flat ? $value : $scrubbedFlat;
        }

        if (str_contains($value, self::REPLACEMENT.'=')) {
            $value = preg_replace('/'.preg_quote(self::REPLACEMENT, '/').'\K=(?:[^\s&,;"\'`<>(){}\[\]?:\\\\]++|'.self::VALUE_BACKSLASH.')*+/', '', $value) ?? self::REPLACEMENT;
        }

        if (str_contains($value, '</') || stripos($value, '\\u003C/') !== false) {
            $value = preg_replace_callback(
                '/(?<head>(?:<|\\\\u003[cC])(?<key>[A-Za-z0-9_.:-]{1,64})(?:>|\\\\u003[eE]))(?:[^<\\\\]++|\\\\(?!u003[cC]))++(?<tail>(?:<|\\\\u003[cC])\/\k<key>(?:>|\\\\u003[eE]))/',
                fn (array $m) => $this->matchesKey($m['key']) ? $m['head'].self::REPLACEMENT.$m['tail'] : $m[0],
                $value,
            ) ?? self::REPLACEMENT;
        }

        return $cards ? self::cards($value) : $value;
    }

    public function url(string $url): string
    {
        return $this->text(str_contains($url, '://') ? $url : $this->urlParts($url));
    }

    public function referer(string $referer): string
    {
        $parts = parse_url($referer);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            return $this->url((string) strtok($referer, '?#'));
        }

        return $this->url($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? ''));
    }

    public function matchesKey(string $key, ?string $parent = null): bool
    {
        return $this->verdicts[$parent."\0".$key] ??= $this->decide($key, $parent);
    }

    public static function secretSegment(string $segment): bool
    {
        $length = strlen($segment);

        if ($length < 16
            || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $segment) === 1
            || preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[1-8][0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12}$/i', $segment) === 1
            || preg_match('/^[a-z0-9]+(?:[-_.][a-z0-9]{1,15})+$/', $segment) === 1 && preg_match('/[a-z0-9]{21,}/', $segment) !== 1
            || preg_match('/^[a-z]{2,8}_[A-Za-z0-9]{6,32}$/', $segment) === 1
            || ctype_digit($segment)) {
            return false;
        }

        $upper = preg_match('/[A-Z]/', $segment) === 1;
        $lower = preg_match('/[a-z]/', $segment) === 1;
        $digit = preg_match('/\d/', $segment) === 1;

        return ($upper && $lower && $digit)
            || ($length >= 20 && (($upper && $lower) || preg_match('/^[0-9a-f]+$/i', $segment) === 1 || ($digit && ($upper || $lower)) || strpbrk($segment, '+/=%') !== false));
    }

    public static function luhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19 || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function urls(string $value): string
    {
        if (str_contains($value, '://')) {
            $value = preg_replace_callback(
                '#\b[a-z][a-z0-9+.\-]{0,20}://[^\s"\'`<>\[\](){}]+#i',
                fn (array $m) => $this->urlParts($m[0]),
                $value,
            ) ?? self::REPLACEMENT;
        }

        if (preg_match('#(?:^|[\s"\'`(=])/[^\s/]++/[^\s"\'`<>]{16}#', $value) === 1) {
            $value = preg_replace_callback(
                '#(?<=^|[\s"\'`(=])/(?!/)[^\s"\'`<>\[\](){}]+#',
                fn (array $m) => $this->urlParts($m[0]),
                $value,
            ) ?? self::REPLACEMENT;
        }

        return $value;
    }

    private function urlParts(string $url): string
    {
        if (preg_match('#^(?<scheme>[a-z][a-z0-9+.\-]*://)?(?:(?<user>[^/?\#@:\s]{0,128}):(?<pass>[^/?\#@\s]{1,256})@)?(?<rest>.*)$#is', $url, $m) !== 1) {
            return self::REPLACEMENT;
        }

        $userinfo = $m['pass'] !== '' ? $m['user'].':'.self::REPLACEMENT.'@' : '';
        $cut = strcspn($m['rest'], '?#');
        $path = substr($m['rest'], 0, $cut);
        $tail = substr($m['rest'], $cut);
        $segments = explode('/', $path);

        foreach ($segments as $i => $segment) {
            if (($m['scheme'] === '' || $i > 0) && self::secretSegment(explode(';', $segment)[0])) {
                $segments[$i] = self::REPLACEMENT;
            }
        }

        return $m['scheme'].$userinfo.implode('/', $segments).$tail;
    }

    private function encodedJson(string $value, int $depth): string
    {
        $out = '';
        $copied = 0;
        $from = 0;
        $length = strlen($value);

        while (preg_match('/"\{\\\\(?:"|u0022)/', $value, $found, PREG_OFFSET_CAPTURE, $from) === 1) {
            $begin = $found[0][1] + 1;
            $end = $length;

            for ($i = $begin; $i < $length;) {
                $i += strcspn($value, '\\"', $i);

                if ($i >= $length) {
                    break;
                }

                if ($value[$i] === '\\') {
                    $i += 2;

                    continue;
                }

                $end = $i;

                break;
            }

            $fits = $end < $length && $end - $begin <= self::MAX_ENCODED_BYTES;
            $out .= substr($value, $copied, $begin - $copied).($fits ? $this->escapedText(substr($value, $begin, $end - $begin), $depth + 1) : self::REPLACEMENT);
            $copied = $end;
            $from = min($end + 1, $length);
        }

        return $copied === 0 ? $value : $out.substr($value, $copied);
    }

    private function escapedText(string $escaped, int $depth): string
    {
        if ($depth > self::MAX_TEXT_DEPTH || ++$this->decodes > self::MAX_DECODES) {
            return self::REPLACEMENT;
        }

        $decoded = strtr($escaped, ['\\\\' => '\\', '\\"' => '"', '\\/' => '/', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\u0022' => '"', '\\u0027' => "'", '\\u0026' => '&', '\\u003C' => '<', '\\u003E' => '>', '\\u003c' => '<', '\\u003e' => '>']);
        $scrubbed = $this->text($decoded, true, $depth);

        return $scrubbed === $decoded ? $escaped : strtr($scrubbed, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);
    }

    private function secretLists(string $value): string
    {
        $opens = [];

        preg_replace_callback('/(?<![A-Za-z0-9_.\-])(["\'`]?)(?<key>[A-Za-z0-9_.\-]{1,64}+)\1[ \t\r\n]{0,64}+[:=][ \t\r\n]{0,64}+\[/', function (array $m) use ($value, &$opens) {
            if ($this->matchesKeyAt($value, ...$m['key'])) {
                $opens[] = $m[0][1] + strlen($m[0][0]) - 1;
            }

            return '';
        }, $value, flags: PREG_OFFSET_CAPTURE);

        $out = '';
        $copied = 0;

        foreach ($opens as $open) {
            if ($open < $copied) {
                continue;
            }

            $out .= substr($value, $copied, $open - $copied).self::REPLACEMENT;
            $copied = self::afterClosingBracket($value, $open);
        }

        return $opens === [] ? $value : $out.substr($value, $copied);
    }

    private static function afterClosingBracket(string $value, int $open): int
    {
        $length = strlen($value);
        $depth = 0;
        $quote = null;

        for ($i = $open; $i < $length;) {
            if ($quote !== null) {
                $i += strcspn($value, '\\'.$quote, $i);

                if ($i >= $length) {
                    break;
                }

                if ($value[$i] === '\\') {
                    $i += 2;

                    continue;
                }

                $quote = null;
                $i++;

                continue;
            }

            $i += strcspn($value, '"\'[]', $i);

            if ($i >= $length) {
                break;
            }

            $char = $value[$i++];

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[') {
                $depth++;
            } elseif (--$depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    private function pairs(string $value, int $depth): string
    {
        return preg_replace_callback($this->pair, function (array $m) use ($value, $depth) {
            $colon = ($m['csep'][0] ?? '') !== '' && $m['csep'][1] >= 0;
            $separator = $colon ? $m['csep'][0] : $m['sep'][0];
            $raw = $colon ? $m['cvalue'][0] : $m['value'][0];
            [$key, $keyAt] = $m['key'];
            $before = $keyAt > 0 ? $value[$keyAt - 1] : '';
            $queryOnly = in_array($before, ['?', '&', '#', ';'], true) && isset(self::QUERY_ONLY_KEYS[self::normalize($key)]);
            $quote = in_array($raw[0], ['"', "'", '`'], true) ? $raw[0] : (in_array(substr($raw, 0, 6), ['\\u0022', '\\u0027'], true) ? substr($raw, 0, 6) : '');

            if (($queryOnly || $this->matchesKeyAt($value, $key, $keyAt)) && preg_match('/^(Bearer|Basic)$/i', $raw) !== 1) {
                return $key.$separator.$quote.self::REPLACEMENT.$quote;
            }

            if ($quote === '' || $depth >= self::MAX_TEXT_DEPTH) {
                return $m[0][0];
            }

            $inner = substr($raw, strlen($quote), -strlen($quote));

            if (strpbrk($inner, '=:[{') === false) {
                return $m[0][0];
            }

            return $key.$separator.$quote.(str_contains($inner, '\\"') ? $this->escapedText($inner, $depth + 1) : $this->pairs($inner, $depth + 1)).$quote;
        }, $value, flags: PREG_OFFSET_CAPTURE) ?? self::REPLACEMENT;
    }

    private function colonValuesWithEquals(string $value): string
    {
        return preg_replace_callback(
            '/(?<![A-Za-z0-9_.\-\[\]])(?<key>[A-Za-z0-9_.\-\[\]]{1,64}+)(?<separator>["\'`]?[ \t]*+:[ \t]*+)(?:[^\s&,;"\'`<>(){}\[\]?=:\\\\]++|'.self::VALUE_BACKSLASH.')++=(?:[^\s&,;"\'`<>(){}\[\]?:\\\\]++|'.self::VALUE_BACKSLASH.')*+/',
            fn (array $m) => $this->matchesKeyAt($value, ...$m['key']) ? $m['key'][0].$m['separator'][0].self::REPLACEMENT : $m[0][0],
            $value,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? self::REPLACEMENT;
    }

    private static function cards(string $value): string
    {
        if (preg_match('/\d{4}/', $value) !== 1) {
            return $value;
        }

        return preg_replace_callback('/(?<!\d)\d++(?:(?:'.self::CARD_SEPARATOR.')\d++){0,7}(?!\d)/', function (array $m) {
            $run = $m[0];

            if (strlen(preg_replace('/\D++/', '', $run) ?? '') < 13) {
                return $run;
            }

            $groups = [];
            $offset = 0;

            foreach (preg_split('/'.self::CARD_SEPARATOR.'/', $run) ?: [] as $group) {
                $position = (int) strpos($run, $group, $offset);
                $groups[] = [$group, $position];
                $offset = $position + strlen($group);
            }

            $spans = [];
            $count = count($groups);

            for ($i = 0; $i < $count; $i++) {
                $digits = '';

                for ($j = $i; $j < $count && strlen($digits) + strlen($groups[$j][0]) <= 19; $j++) {
                    $digits .= $groups[$j][0];

                    if (strlen($digits) >= 13 && self::luhn($digits)) {
                        $spans[] = [$groups[$i][1], $groups[$j][1] + strlen($groups[$j][0])];
                        $i = $j;

                        continue 2;
                    }
                }

                [$group, $position] = $groups[$i];

                if (strlen($group) > 19) {
                    $spans[] = [$position, $position + strlen($group)];
                }
            }

            foreach (array_reverse($spans) as [$from, $to]) {
                $run = substr($run, 0, $from).self::REPLACEMENT.substr($run, $to);
            }

            return $run;
        }, $value) ?? self::REPLACEMENT;
    }

    private function cardExempt(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        $segments = self::segments($key);
        $last = end($segments);

        return $last === 'id' || $last === 'ids' || isset(self::CARD_EXEMPT_KEYS[implode('', $segments)]);
    }

    private function matchesKeyAt(string $value, string $key, int $at): bool
    {
        return $this->matchesKey($key) || ($at > 0 && $value[$at - 1] === '\\' && $this->matchesKey(preg_replace('/^(?:[nrt]|'.self::HEX_ESCAPE.')/', '', $key) ?? $key));
    }

    private function decide(string $key, ?string $parent): bool
    {
        if (count($this->verdicts) > 4096) {
            $this->verdicts = [];
        }

        $own = self::segments(substr($key, 0, 128));
        $count = count($own);

        if ($count > 0 && isset($this->keys[implode('', $own)])) {
            return true;
        }

        if ($count === 0 || isset(self::SAFE_PREFIX[$own[0]]) || ($count > 1 && isset(self::SAFE_SUFFIX[$own[$count - 1]])) || isset(self::SAFE_KEYS[implode('', $own)])) {
            return false;
        }

        if (isset(self::TERMINAL[$own[$count - 1]])) {
            return true;
        }

        if (strlen($own[$count - 1]) > 3 && str_ends_with($own[$count - 1], 's') && isset($this->keys[substr($own[$count - 1], 0, -1)])) {
            return true;
        }

        $inherited = $parent === null ? [] : array_slice(self::segments(substr($parent, 0, 128)), -2);
        $segments = [...$inherited, ...$own];
        $first = count($inherited);
        $total = count($segments);

        for ($start = 0; $start < $total; $start++) {
            $run = '';

            for ($end = $start; $end < $total; $end++) {
                $run .= $segments[$end];

                if ($end >= $first && isset($this->keys[$run])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function segments(string $key): array
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key) ?? $key;

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($spaced)) ?: [], fn (string $s) => $s !== ''));
    }

    private static function normalize(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';
    }
}
