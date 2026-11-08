<?php

namespace justinholtweb\eat\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\FileHelper;
use justinholtweb\eat\models\Feed;
use justinholtweb\eat\Plugin;
use Throwable;

/**
 * The one place a generated file leaves the plugin.
 *
 * Everything — the local file, an asset volume, FTP, SFTP and the Merchant API — lands here, so a
 * merchant reading the run log is reading one story, not five.
 */
class Delivery extends Component
{
    public const MODE_FILE = 'file';
    public const MODE_VOLUME = 'volume';
    public const MODE_FTP = 'ftp';
    public const MODE_SFTP = 'sftp';
    public const MODE_MERCHANT = 'merchant';

    public static function modes(): array
    {
        return [
            self::MODE_FILE => Craft::t('eat', 'Write a file the channel can fetch'),
            self::MODE_VOLUME => Craft::t('eat', 'Write into an asset volume'),
            self::MODE_FTP => Craft::t('eat', 'Upload over FTP'),
            self::MODE_SFTP => Craft::t('eat', 'Upload over SFTP'),
            self::MODE_MERCHANT => Craft::t('eat', 'Push to Google Merchant Center (Merchant API)'),
        ];
    }

    /**
     * @return string[] Modes Lite may use.
     */
    public static function liteModes(): array
    {
        return [self::MODE_FILE];
    }

    /**
     * Put a generated file where the feed says it belongs.
     *
     * The local copy is always written first: the file URL keeps working even when the remote
     * upload fails, and a merchant can look at exactly what was sent.
     *
     * @return array{ok: bool, url: string|null, results: array<int, array<string, mixed>>}
     */
    public function deliver(Feed $feed, string $tempPath): array
    {
        $mode = $feed->getDeliveryMode();
        $pro = Plugin::getInstance()->isPro();

        if (!$pro && !in_array($mode, self::liteModes(), true)) {
            $mode = self::MODE_FILE;
        }

        $results = [];
        $local = $this->_writeLocal($feed, $tempPath);
        $results[] = $local;
        $url = $local['url'] ?? null;

        try {
            switch ($mode) {
                case self::MODE_VOLUME:
                    $volume = $this->_writeVolume($feed, $tempPath);
                    $results[] = $volume;
                    $url = $volume['url'] ?? $url;
                    break;

                case self::MODE_FTP:
                    $results[] = $this->_uploadFtp($feed, $tempPath);
                    break;

                case self::MODE_SFTP:
                    $results[] = $this->_uploadSftp($feed, $tempPath);
                    break;

                case self::MODE_MERCHANT:
                    $results[] = Plugin::getInstance()->getMerchant()->push($feed);
                    break;
            }
        } catch (Throwable $e) {
            $results[] = ['mode' => $mode, 'ok' => false, 'message' => $e->getMessage()];
        }

        $ok = true;

        foreach ($results as $result) {
            $ok = $ok && (bool)($result['ok'] ?? false);
        }

        return ['ok' => $ok, 'url' => $url, 'results' => $results];
    }

    /**
     * The public URL of a feed written into an asset volume.
     */
    public function getVolumeUrl(Feed $feed): ?string
    {
        $delivery = $feed->getDelivery();
        $volumeId = (int)($delivery['volumeId'] ?? 0);

        if (!$volumeId) {
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if ($volume === null) {
            return null;
        }

        try {
            $fs = $volume->getFs();

            if (!$fs->hasUrls) {
                return null;
            }

            $root = rtrim((string)App::parseEnv($fs->url), '/');
        } catch (Throwable) {
            return null;
        }

        $path = trim($this->_volumePath($feed), '/');

        return $root . '/' . $path;
    }

    // Destinations
    // -------------------------------------------------------------------------

    private function _writeLocal(Feed $feed, string $tempPath): array
    {
        $target = $feed->getFilePath();

        try {
            FileHelper::createDirectory(dirname($target));

            // Written beside the target and renamed into place: a channel fetching the URL mid-run
            // gets the previous feed, never half of the new one.
            $staging = $target . '.tmp';

            if (!@copy($tempPath, $staging)) {
                throw new \RuntimeException("Could not write “{$staging}”.");
            }

            if (!@rename($staging, $target)) {
                FileHelper::unlink($staging);
                throw new \RuntimeException("Could not move the feed into “{$target}”.");
            }

            @chmod($target, 0664);

            return ['mode' => self::MODE_FILE, 'ok' => true, 'path' => $target, 'url' => $feed->getUrl()];
        } catch (Throwable $e) {
            return ['mode' => self::MODE_FILE, 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function _writeVolume(Feed $feed, string $tempPath): array
    {
        $delivery = $feed->getDelivery();
        $volumeId = (int)($delivery['volumeId'] ?? 0);
        $volume = $volumeId ? Craft::$app->getVolumes()->getVolumeById($volumeId) : null;

        if ($volume === null) {
            return ['mode' => self::MODE_VOLUME, 'ok' => false, 'message' => Craft::t('eat', 'No such asset volume.')];
        }

        $stream = fopen($tempPath, 'rb');

        if ($stream === false) {
            return ['mode' => self::MODE_VOLUME, 'ok' => false, 'message' => 'Could not read the generated file.'];
        }

        try {
            $path = $this->_volumePath($feed);
            $staging = $path . '.tmp';
            $fs = $volume->getFs();

            // Staged and renamed, as the local file is. On a local filesystem that is an atomic
            // rename(); on S3 and its kin it is a server-side copy, which replaces the object
            // whole. Either way a channel fetching mid-run gets the previous feed.
            $fs->writeFileFromStream($staging, $stream, []);
            $fs->renameFile($staging, $path);

            // Craft's local filesystem swallows a failed rename. Leaving the old feed in place
            // would be a silent stale feed, so write it directly instead.
            if ($fs->fileExists($staging)) {
                $retry = fopen($tempPath, 'rb');

                try {
                    $fs->writeFileFromStream($path, $retry, []);
                } finally {
                    if (is_resource($retry)) {
                        fclose($retry);
                    }
                }

                $fs->deleteFile($staging);
            }

            return ['mode' => self::MODE_VOLUME, 'ok' => true, 'path' => $path, 'url' => $this->getVolumeUrl($feed)];
        } catch (Throwable $e) {
            return ['mode' => self::MODE_VOLUME, 'ok' => false, 'message' => $e->getMessage()];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function _volumePath(Feed $feed): string
    {
        $delivery = $feed->getDelivery();
        $subpath = trim((string)($delivery['volumePath'] ?? ''), '/');

        return ($subpath !== '' ? $subpath . '/' : '') . $feed->getFileName();
    }

    private function _uploadFtp(Feed $feed, string $tempPath): array
    {
        $config = $feed->getDelivery()['ftp'] ?? [];
        $host = (string)App::parseEnv((string)($config['host'] ?? ''));
        $username = (string)App::parseEnv((string)($config['username'] ?? ''));
        $password = (string)App::parseEnv((string)($config['password'] ?? ''));
        $port = (int)($config['port'] ?? 21) ?: 21;
        $remote = $this->_remotePath((string)($config['path'] ?? ''), $feed);

        if (!function_exists('ftp_connect')) {
            return ['mode' => self::MODE_FTP, 'ok' => false, 'message' => Craft::t('eat', 'PHP’s FTP extension is not installed.')];
        }

        if ($host === '') {
            return ['mode' => self::MODE_FTP, 'ok' => false, 'message' => Craft::t('eat', 'No FTP host.')];
        }

        $connection = !empty($config['secure']) && function_exists('ftp_ssl_connect')
            ? @ftp_ssl_connect($host, $port, 30)
            : @ftp_connect($host, $port, 30);

        if ($connection === false) {
            return ['mode' => self::MODE_FTP, 'ok' => false, 'message' => Craft::t('eat', 'Could not connect to {host}.', ['host' => $host])];
        }

        try {
            if (!@ftp_login($connection, $username, $password)) {
                return ['mode' => self::MODE_FTP, 'ok' => false, 'message' => Craft::t('eat', 'FTP login failed.')];
            }

            @ftp_pasv($connection, (bool)($config['passive'] ?? true));

            // Uploaded beside the target and renamed over it, so a channel fetching mid-upload
            // gets the previous feed rather than the first half of this one.
            $staging = $remote . '.tmp';

            if (!@ftp_put($connection, $staging, $tempPath, FTP_BINARY)) {
                @ftp_delete($connection, $staging);

                return ['mode' => self::MODE_FTP, 'ok' => false, 'message' => Craft::t('eat', 'FTP upload failed.')];
            }

            // Unix servers rename over an existing file; some Windows servers refuse to, and
            // need the old one deleted first. That leaves a moment with no file — a 404 the
            // channel retries, never a truncated feed.
            $renamed = @ftp_rename($connection, $staging, $remote);

            if (!$renamed) {
                @ftp_delete($connection, $remote);
                $renamed = @ftp_rename($connection, $staging, $remote);
            }

            if (!$renamed) {
                @ftp_delete($connection, $staging);

                return ['mode' => self::MODE_FTP, 'ok' => false, 'message' => Craft::t('eat', 'The upload worked but the FTP server would not rename it into place.')];
            }

            return ['mode' => self::MODE_FTP, 'ok' => true, 'path' => $remote];
        } finally {
            @ftp_close($connection);
        }
    }

    private function _uploadSftp(Feed $feed, string $tempPath): array
    {
        $config = $feed->getDelivery()['sftp'] ?? [];

        if (!class_exists(\phpseclib3\Net\SFTP::class)) {
            return [
                'mode' => self::MODE_SFTP,
                'ok' => false,
                'message' => Craft::t('eat', 'SFTP delivery needs phpseclib: composer require phpseclib/phpseclib ^3.0'),
            ];
        }

        $host = (string)App::parseEnv((string)($config['host'] ?? ''));
        $username = (string)App::parseEnv((string)($config['username'] ?? ''));
        $password = (string)App::parseEnv((string)($config['password'] ?? ''));
        $privateKey = (string)App::parseEnv((string)($config['privateKey'] ?? ''));
        $port = (int)($config['port'] ?? 22) ?: 22;
        $remote = $this->_remotePath((string)($config['path'] ?? ''), $feed);

        if ($host === '') {
            return ['mode' => self::MODE_SFTP, 'ok' => false, 'message' => Craft::t('eat', 'No SFTP host.')];
        }

        $sftp = new \phpseclib3\Net\SFTP($host, $port);
        $credential = $password;

        if ($privateKey !== '') {
            $key = is_file($privateKey) ? file_get_contents($privateKey) : $privateKey;
            $credential = \phpseclib3\Crypt\PublicKeyLoader::load((string)$key, $password ?: false);
        }

        if (!$sftp->login($username, $credential)) {
            return ['mode' => self::MODE_SFTP, 'ok' => false, 'message' => Craft::t('eat', 'SFTP login failed.')];
        }

        $staging = $remote . '.tmp';

        if (!$sftp->put($staging, $tempPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            $sftp->delete($staging);

            return ['mode' => self::MODE_SFTP, 'ok' => false, 'message' => Craft::t('eat', 'SFTP upload failed.')];
        }

        // SFTP v3's rename refuses an existing target. OpenSSH's posix-rename extension replaces
        // it atomically; without it, delete-then-rename leaves a moment with no file rather than
        // a moment with half of one.
        $renamed = method_exists($sftp, 'posix_rename') && $sftp->posix_rename($staging, $remote);

        if (!$renamed) {
            $renamed = $sftp->rename($staging, $remote);
        }

        if (!$renamed) {
            $sftp->delete($remote);
            $renamed = $sftp->rename($staging, $remote);
        }

        if (!$renamed) {
            $sftp->delete($staging);

            return ['mode' => self::MODE_SFTP, 'ok' => false, 'message' => Craft::t('eat', 'The upload worked but the SFTP server would not rename it into place.')];
        }

        return ['mode' => self::MODE_SFTP, 'ok' => true, 'path' => $remote];
    }

    /**
     * A remote path may be a directory, a full filename, or nothing at all.
     */
    private function _remotePath(string $configured, Feed $feed): string
    {
        $configured = trim((string)App::parseEnv($configured));

        if ($configured === '') {
            return $feed->getFileName();
        }

        if (str_ends_with($configured, '/') || !str_contains(basename($configured), '.')) {
            return rtrim($configured, '/') . '/' . $feed->getFileName();
        }

        return $configured;
    }
}
