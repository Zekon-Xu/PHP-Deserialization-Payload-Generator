<?php

class PhpGgcRunner
{
    private $root;
    private $phpExe;
    private $phpggcScript;
    private $phpggcDir;
    private $logFile;

    public function __construct(string $root)
    {
        $this->root = realpath($root) ?: $root;
        $this->phpExe = $this->root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
        $this->phpggcScript = $this->root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpggc' . DIRECTORY_SEPARATOR . 'phpggc';
        $this->phpggcDir = dirname($this->phpggcScript);
        $this->logFile = $this->root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'phpggc_audit.log';
    }

    public function env(): array
    {
        $phpExists = is_file($this->phpExe);
        $phpggcExists = is_file($this->phpggcScript);
        $licensePath = $this->phpggcDir . DIRECTORY_SEPARATOR . 'LICENSE';
        $phpVersion = '';
        $phpggcReady = false;
        $error = '';

        if ($phpExists) {
            $result = $this->runCommand([$this->phpExe, '-v'], 8, dirname($this->phpExe));
            $phpVersion = trim(strtok($result['stdout'], "\r\n") ?: '');
            if ($result['exit_code'] !== 0) {
                $error = trim($result['stderr'] ?: $result['stdout']);
            }
        }

        if ($phpExists && $phpggcExists) {
            $result = $this->runPhpGgc(['-l'], 15);
            $phpggcReady = $result['exit_code'] === 0 && strpos($result['stdout'], 'Gadget Chains') !== false;
            if (!$phpggcReady && !$error) {
                $error = trim($result['stderr'] ?: $result['stdout']);
            }
        }

        return [
            'ok' => $phpExists && $phpggcExists && $phpggcReady,
            'php_exists' => $phpExists,
            'phpggc_exists' => $phpggcExists,
            'phpggc_ready' => $phpggcReady,
            'php_version' => $phpVersion,
            'php_path' => $this->relativePath($this->phpExe),
            'phpggc_path' => $this->relativePath($this->phpggcScript),
            'license_path' => is_file($licensePath) ? $this->relativePath($licensePath) : '',
            'official_repo' => 'https://github.com/ambionics/phpggc',
            'license' => 'Apache-2.0',
            'error' => $error,
        ];
    }

    public function chains(array $filters = []): array
    {
        $result = $this->runPhpGgc(['-l'], 20);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(trim($result['stderr'] ?: $result['stdout']) ?: 'PHPGGC chain 列表读取失败');
        }

        $chains = $this->parseChainList($result['stdout']);
        $search = strtolower(trim((string)($filters['search'] ?? '')));
        $framework = trim((string)($filters['framework'] ?? ''));
        $type = trim((string)($filters['type'] ?? ''));
        $vector = trim((string)($filters['vector'] ?? ''));

        $chains = array_values(array_filter($chains, function (array $chain) use ($search, $framework, $type, $vector): bool {
            if ($framework !== '' && $chain['framework'] !== $framework) {
                return false;
            }
            if ($type !== '' && $chain['type'] !== $type) {
                return false;
            }
            if ($vector !== '' && $chain['vector'] !== $vector) {
                return false;
            }
            if ($search !== '') {
                $haystack = strtolower(implode(' ', [
                    $chain['name'],
                    $chain['framework'],
                    $chain['version'],
                    $chain['type'],
                    $chain['vector'],
                ]));
                return strpos($haystack, $search) !== false;
            }
            return true;
        }));

        return [
            'chains' => $chains,
            'filters' => [
                'frameworks' => $this->uniqueSorted(array_column($chains, 'framework')),
                'types' => $this->uniqueSorted(array_column($chains, 'type')),
                'vectors' => $this->uniqueSorted(array_column($chains, 'vector')),
            ],
            'count' => count($chains),
        ];
    }

    public function info(string $chain): array
    {
        $this->assertSafeChain($chain);
        $result = $this->runPhpGgc(['-i', $chain], 15);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(trim($result['stderr'] ?: $result['stdout']) ?: 'PHPGGC chain 详情读取失败');
        }

        $raw = trim($result['stdout']);
        $fields = [];
        foreach (preg_split('/\R/u', $raw) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z ]+)\s*:\s*(.*)$/u', $line, $match)) {
                $key = strtolower(str_replace(' ', '_', trim($match[1])));
                $fields[$key] = trim($match[2]);
            }
        }

        $usage = '';
        foreach (preg_split('/\R/u', $raw) as $line) {
            if (strpos($line, './phpggc') !== false && strpos($line, $chain) !== false) {
                $usage = trim($line);
            }
        }

        return [
            'name' => $fields['name'] ?? $chain,
            'version' => $fields['version'] ?? '',
            'type' => $fields['type'] ?? '',
            'vector' => $fields['vector'] ?? '',
            'usage' => $usage,
            'arguments' => $this->parseUsageArguments($usage, $chain),
            'raw' => $raw,
        ];
    }

    public function generate(string $chain, array $arguments, array $options, bool $authorized): array
    {
        $this->assertSafeChain($chain);
        if (!$authorized) {
            throw new RuntimeException('请先确认仅用于授权测试、CTF、实验室或防御研究');
        }

        $safeArguments = [];
        foreach ($arguments as $argument) {
            $value = (string)$argument;
            if (strlen($value) > 20000) {
                throw new RuntimeException('单个参数过长，请缩短后重试');
            }
            $safeArguments[] = $value;
        }
        if (count($safeArguments) > 24) {
            throw new RuntimeException('参数数量过多');
        }

        $commandArgs = array_merge($this->optionArgs($options), [$chain], $safeArguments);
        $result = $this->runPhpGgc($commandArgs, 30);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(trim($result['stderr'] ?: $result['stdout']) ?: 'PHPGGC payload 生成失败');
        }

        $payload = $result['stdout'];
        $payloadText = $this->isUtf8($payload) ? $payload : null;
        $this->appendAudit([
            'time' => date('c'),
            'chain' => $chain,
            'args_count' => count($safeArguments),
            'arg_lengths' => array_map('strlen', $safeArguments),
            'options' => array_keys(array_filter($options)),
            'payload_sha256' => hash('sha256', $payload),
            'payload_bytes' => strlen($payload),
        ]);

        return [
            'chain' => $chain,
            'payload' => $payloadText,
            'payload_base64' => base64_encode($payload),
            'binary' => $payloadText === null,
            'bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
        ];
    }

    public function audit(int $limit = 50): array
    {
        if (!is_file($this->logFile)) {
            return ['items' => []];
        }
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, max(0, count($lines) - $limit));
        $items = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return ['items' => array_reverse($items)];
    }

    private function runPhpGgc(array $args, int $timeout): array
    {
        if (!is_file($this->phpExe)) {
            throw new RuntimeException('未找到项目内置 PHP CLI：runtime/php/php.exe');
        }
        if (!is_file($this->phpggcScript)) {
            throw new RuntimeException('未找到项目内置 PHPGGC：vendor/phpggc/phpggc');
        }
        return $this->runCommand(array_merge([$this->phpExe, $this->phpggcScript], $args), $timeout, $this->phpggcDir);
    }

    private function runCommand(array $parts, int $timeout, string $cwd): array
    {
        $command = implode(' ', array_map([$this, 'escapeArgument'], $parts));
        $previousCwd = getcwd();
        if ($previousCwd === false) {
            $previousCwd = null;
        }
        if (!@chdir($cwd)) {
            throw new RuntimeException('无法进入 PHPGGC 工作目录');
        }
        $previousPhprc = getenv('PHPRC');
        putenv('PHPRC=' . dirname($this->phpExe));

        $lines = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $lines, $exitCode);

        if ($previousPhprc === false) {
            putenv('PHPRC');
        } else {
            putenv('PHPRC=' . $previousPhprc);
        }
        if ($previousCwd !== null) {
            @chdir($previousCwd);
        }

        return ['exit_code' => $exitCode, 'stdout' => implode("\n", $lines), 'stderr' => ''];
    }

    private function escapeArgument(string $argument): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $escaped = str_replace('"', '\"', $argument);
            return '"' . $escaped . '"';
        }
        return escapeshellarg($argument);
    }

    private function parseChainList(string $output): array
    {
        $lines = preg_split('/\R/u', $output);
        $header = null;
        foreach ($lines as $line) {
            if (strpos($line, 'NAME') !== false && strpos($line, 'VERSION') !== false && strpos($line, 'TYPE') !== false) {
                $header = $line;
                break;
            }
        }
        if ($header === null) {
            return [];
        }

        $positions = [
            'name' => strpos($header, 'NAME'),
            'version' => strpos($header, 'VERSION'),
            'type' => strpos($header, 'TYPE'),
            'vector' => strpos($header, 'VECTOR'),
            'i' => strrpos($header, 'I'),
        ];

        $chains = [];
        $afterHeader = false;
        foreach ($lines as $line) {
            if (!$afterHeader) {
                $afterHeader = $line === $header;
                continue;
            }
            if (trim($line) === '' || preg_match('/^-+$/', trim($line))) {
                continue;
            }
            $padded = str_pad($line, 140);
            $name = trim(substr($padded, $positions['name'], $positions['version'] - $positions['name']));
            if ($name === '' || $name === 'NAME') {
                continue;
            }
            $version = trim(substr($padded, $positions['version'], $positions['type'] - $positions['version']));
            $type = trim(substr($padded, $positions['type'], $positions['vector'] - $positions['type']));
            $vector = trim(substr($padded, $positions['vector'], $positions['i'] - $positions['vector']));
            $flag = trim(substr($padded, $positions['i']));
            $parts = explode('/', $name);
            $chains[] = [
                'name' => $name,
                'framework' => $parts[0] ?? $name,
                'version' => $version,
                'type' => $type,
                'vector' => $vector,
                'has_info' => strpos($flag, '*') !== false,
            ];
        }
        return $chains;
    }

    private function parseUsageArguments(string $usage, string $chain): array
    {
        if ($usage === '') {
            return [];
        }
        $pos = strpos($usage, $chain);
        $tail = $pos === false ? $usage : substr($usage, $pos + strlen($chain));
        preg_match_all('/(<([^>]+)>|\[([^\]]+)\])/u', $tail, $matches, PREG_SET_ORDER);
        $arguments = [];
        foreach ($matches as $index => $match) {
            $name = trim($match[2] !== '' ? $match[2] : $match[3]);
            $arguments[] = [
                'name' => $name ?: 'arg' . ($index + 1),
                'required' => $match[2] !== '',
                'placeholder' => $match[0],
            ];
        }
        return $arguments;
    }

    private function optionArgs(array $options): array
    {
        $map = [
            'fast_destruct' => '-f',
            'public_properties' => '-pub',
            'ascii_strings' => '-a',
            'armor_strings' => '-A',
            'session_encode' => '-se',
            'soft_urlencode' => '-s',
            'urlencode' => '-u',
            'base64' => '-b',
            'json' => '-j',
        ];
        $args = [];
        foreach ($map as $key => $flag) {
            if (!empty($options[$key])) {
                $args[] = $flag;
            }
        }
        return $args;
    }

    private function assertSafeChain(string $chain): void
    {
        if ($chain === '' || strlen($chain) > 160 || strpos($chain, '..') !== false || !preg_match('/^[A-Za-z0-9_.+\/-]+$/', $chain)) {
            throw new RuntimeException('chain 名称不合法');
        }
    }

    private function appendAudit(array $item): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $this->logFile,
            json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map('strval', $values), function ($value) {
            return $value !== '';
        })));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);
        return $values;
    }

    private function relativePath(string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->root), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $path);
        if (stripos($normalizedPath, $normalizedRoot) === 0) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }
        return $path;
    }

    private function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
