<?php

declare(strict_types=1);

final class LegacyOfficeConverterException extends RuntimeException
{
}

final class LegacyOfficeConverter
{
    public function convert(string $path, string $extension): array
    {
        $binary = $this->binary();
        if ($binary === null) throw new LegacyOfficeConverterException('服务器缺少 LibreOffice/soffice 旧版 Office 转换能力');
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lesson-office-' . bin2hex(random_bytes(12));
        if (!mkdir($root, 0700, true)) throw new LegacyOfficeConverterException('无法创建 Office 转换临时目录');
        $input = $root . DIRECTORY_SEPARATOR . 'source.' . $extension;
        if (!copy($path, $input)) throw new LegacyOfficeConverterException('无法准备 Office 转换文件');
        $target = $extension === 'xls' ? 'xlsx' : 'docx';
        $profile = $root . DIRECTORY_SEPARATOR . 'profile';
        if (!mkdir($profile, 0700, true)) throw new LegacyOfficeConverterException('无法创建 Office 隔离配置目录');
        $command = escapeshellarg($binary) . ' -env:UserInstallation=' . escapeshellarg('file://' . $profile) . ' --headless --nologo --nodefault --nofirststartwizard --convert-to ' . escapeshellarg($target) . ' --outdir ' . escapeshellarg($root) . ' ' . escapeshellarg($input);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) throw new LegacyOfficeConverterException('无法启动 Office 转换器');
        try {
            $started = microtime(true);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $status = proc_get_status($process);
            while ($status['running']) {
                if (microtime(true) - $started > 30) throw new LegacyOfficeConverterException('Office 转换超时');
                usleep(100000);
                $status = proc_get_status($process);
            }
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            $exit = (int) ($status['exitcode'] ?? -1);
            if ($exit !== 0 && $exit !== -1) throw new LegacyOfficeConverterException('Office 文件转换失败：' . trim($stderr ?: $stdout));
        } catch (Throwable $error) {
            if (is_resource($process)) proc_terminate($process);
            throw $error;
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) proc_close($process);
        }
        $converted = $root . DIRECTORY_SEPARATOR . 'source.' . $target;
        if (!is_file($converted) || filesize($converted) < 1) {
            throw new LegacyOfficeConverterException('Office 文件转换失败：' . trim($stderr ?: $stdout));
        }
        return [$converted, $target];
    }

    private function binary(): ?string
    {
        foreach (['LESSON_OFFICE_BINARY', '/usr/bin/libreoffice', '/usr/bin/soffice', '/usr/local/bin/libreoffice', '/usr/local/bin/soffice'] as $candidate) {
            $value = getenv($candidate) ?: $candidate;
            if ($value !== '' && is_file($value) && is_executable($value)) return $value;
        }
        return null;
    }
}
