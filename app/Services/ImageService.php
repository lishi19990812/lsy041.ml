<?php

namespace App\Services;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Green\Green;
use App\Enums\ConfigKey;
use App\Enums\GroupConfigKey;
use App\Enums\ImagePermission;
use App\Enums\Scan\AliyunOption;
use App\Enums\Scan\NsfwJsOption;
use App\Enums\Scan\TencentOption;
use App\Enums\Strategy\CosOption;
use App\Enums\Strategy\FtpOption;
use App\Enums\Strategy\KodoOption;
use App\Enums\Strategy\LocalOption;
use App\Enums\Strategy\MinioOption;
use App\Enums\Strategy\OssOption;
use App\Enums\Strategy\S3Option;
use App\Enums\Strategy\SftpOption;
use App\Enums\Strategy\UssOption;
use App\Enums\Strategy\WebDavOption;
use App\Enums\StrategyKey;
use App\Enums\UserConfigKey;
use App\Enums\UserStatus;
use App\Enums\Watermark\FontOption;
use App\Enums\Watermark\ImageOption;
use App\Enums\Watermark\Mode;
use App\Exceptions\UploadException;
use App\Models\Group;
use App\Models\Image;
use App\Models\Strategy;
use App\Models\User;
use App\Utils;
use Aws\S3\S3Client;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image as InterventionImage;
use Intervention\Image\Imagick\Font;
use Intervention\Image\ImageManager;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PhpseclibV2\SftpAdapter;
use League\Flysystem\PhpseclibV2\SftpConnectionProvider;
use League\Flysystem\WebDAV\WebDAVAdapter;
use Overtrue\Flysystem\Cos\CosAdapter;
use Overtrue\Flysystem\Qiniu\QiniuAdapter;
use Sabre\DAV\Client;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ims\V20201229\ImsClient;
use TencentCloud\Ims\V20201229\Models\ImageModerationRequest;
use WispX\Flysystem\Upyun\UpyunAdapter;
use Zing\Flysystem\Oss\OssAdapter;
use OSS\OssClient;

class ImageService
{
    /**
     * @param  Request  $request
     * @return Image
     * @throws UploadException
     */
    public function store(Request $request): Image
    {
        $file = $request->file('file');

        if (Auth::guest() && !Utils::config(ConfigKey::IsAllowGuestUpload, true)) {
            throw new UploadException('??????????????????????????????');
        }

        $image = new Image();
        /** @var User|null $user */
        $user = $request->user();

        /** @var Group $group */
        $group = ! is_null($user) ? $user->group : Group::query()->where('is_guest', true)->first();
        // ?????????
        $configs = $group->configs;
        // ????????????
        $strategies = $group->strategies()->get();
        if ($strategies->isEmpty()) {
            throw new UploadException('?????????????????????????????????????????????');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $configs->get(GroupConfigKey::AcceptedFileSuffixes))) {
            throw new UploadException('????????????????????????');
        }

        if ($file->getSize() / 1024 > $configs->get(GroupConfigKey::MaximumFileSize)) {
            throw new UploadException("????????????????????????");
        }

        // ???????????????
        $image->group_id = $group->id;
        // ??????????????????
        $image->permission = ImagePermission::Private;

        if ($request->has('strategy_id')) {
            if (! $strategy = $strategies->find($request->input('strategy_id'))) {
                throw new UploadException('????????????????????????');
            }
        } else {
            // ????????????????????????????????????
            $strategy = $strategies->first();
        }

        $image->strategy_id = $strategy->id;

        // ??????????????????
        if (! is_null($user)) {
            if (Utils::config(ConfigKey::IsUserNeedVerify) && ! $user->email_verified_at) {
                throw new UploadException('???????????????');
            }

            if ($user->status !== UserStatus::Normal) {
                throw new UploadException('??????????????????');
            }

            if ($file->getSize() / 1024 + $user->images()->sum('size') > $user->capacity) {
                throw new UploadException('??????????????????');
            }

            // ???????????????????????????(??????)
            if ($albumId = $user->configs->get(UserConfigKey::DefaultAlbum)) {
                if ($user->albums()->where('id', $albumId)->exists()) {
                    $image->album_id = $albumId;
                }
            }

            $image->user_id = $user->id;
            // ?????????????????????????????????
            $image->permission = $user->configs->get(UserConfigKey::DefaultPermission, ImagePermission::Private);
        }

        // ??????????????????
        $this->rateLimiter($configs, $request);

        // ????????????????????? ico ??? gif
        if (! in_array($extension, ['ico', 'gif'])) {
            // ???????????????????????????
            $quality = $configs->get(GroupConfigKey::ImageSaveQuality, 75);
            $format = $configs->get(GroupConfigKey::ImageSaveFormat);
            if ($quality < 100 || $format) {
                // ??????????????????????????????????????????
                $format = $format ?: $extension;
                $filename = Str::replaceLast($extension, $format, $file->getClientOriginalName());
                $handleImage = InterventionImage::make($file)->save($format, $quality);
                $file = new UploadedFile($handleImage->basePath(), $filename, $handleImage->mime());
                // ?????????????????????
                $extension = $format;
                $handleImage->destroy();
            }

            // ????????????????????????????????????
            if (
                $configs->get(GroupConfigKey::IsEnableWatermark) &&
                collect($configs->get(GroupConfigKey::WatermarkConfigs))->get('mode', Mode::Overlay) == Mode::Overlay
            ) {
                $watermarkImage = $this->stickWatermark($file, collect($configs->get(GroupConfigKey::WatermarkConfigs)));
                $watermarkImage->save();
                $file = new UploadedFile($watermarkImage->basePath(), $file->getClientOriginalName(), $file->getMimeType());
                $watermarkImage->destroy();
            }
        }

        $filename = $this->replacePathname(
            $configs->get(GroupConfigKey::PathNamingRule).'/'.$configs->get(GroupConfigKey::FileNamingRule), $file,
        );
        $pathname = $filename.".{$extension}";

        [$width, $height] = @getimagesize($file->getRealPath()) ?: [400, 400];

        $image->fill([
            'md5' => md5_file($file->getRealPath()),
            'sha1' => sha1_file($file->getRealPath()),
            'path' => $configs->get(GroupConfigKey::PathNamingRule) ? dirname($pathname) : '',
            'name' => basename($pathname),
            'origin_name' => $file->getClientOriginalName(),
            'size' => $file->getSize() / 1024,
            'mimetype' => $file->getMimeType(),
            'extension' => strtolower($extension),
            'width' => $width,
            'height' => $height,
            'is_unhealthy' => false,
            'uploaded_ip' => $request->ip(),
        ]);

        $filesystem = new Filesystem($this->getAdapter($strategy));

        // ???????????????????????????????????????????????????????????????????????????
        /** @var Image $existing */
        $existing = Image::query()->when($image->strategy_id, function (Builder $builder, $id) {
            $builder->where('strategy_id', $id);
        })->where('md5', $image->md5)->where('sha1', $image->sha1)->first();
        if (is_null($existing)) {
            $handle = fopen($file, 'r');
            try {
                $filesystem->writeStream($pathname, $handle);
            } catch (FilesystemException $e) {
                Utils::e($e, '???????????????????????????');
                throw new UploadException(config('app.debug', false) ? $e->getMessage() : '??????????????????');
            }
            if (is_resource($handle)) @fclose($handle);
        } else {
            $image->fill($existing->only('path', 'name'));
        }

        // ??????????????????????????????????????????????????????
        $updateNum = function ($method) use ($user, $image) {
            DB::transaction(function () use ($method, $user, $image) {
                if (! is_null($user)) {
                    $user->{$method}('image_num');
                }
                if (! is_null($image->album)) {
                    $image->album->{$method}('image_num');
                }
            });
        };

        try {
            // ?????????????????????????????????????????????
            if (! $image->save()) {
                if (is_null($existing)) $filesystem->delete($image->pathname);
                throw new \Exception('????????????????????????');
            }
            $updateNum('increment');
        } catch (\Throwable $e) {
            Utils::e($e, '?????????????????????????????????');
            throw new UploadException('????????????????????????');
        }

        // ????????????????????? tif???ico ?????? psd ??????
        if ($configs->get(GroupConfigKey::IsEnableScan) && ! in_array($extension, ['psd', 'ico', 'tif'])) {
            $scanConfigs = $configs->get(GroupConfigKey::ScanConfigs);
            if ($this->scan(
                driver: $scanConfigs['driver'],
                configs: collect($scanConfigs['drivers'][$scanConfigs['driver']]),
                image: $image,
                file: $file,
            )) {
                // ?????? or ??????
                if ($configs->get(GroupConfigKey::ScannedAction) === 'delete') {
                    $image->delete();
                    $updateNum('decrement');
                    throw new UploadException('????????????????????????????????????');
                } else {
                    $image->is_unhealthy = true;
                    $image->save();
                }
            }
        }

        // ???????????????
        $this->makeThumbnail($image, $file);

        return $image;
    }

    public function getAdapter(Strategy $strategy): FilesystemAdapter
    {
        $configs = $strategy->configs;
        return match ($strategy->key) {
            StrategyKey::Local => new LocalFilesystemAdapter(
                location: $configs->get(LocalOption::Root) ?: config('filesystems.disks.uploads.root')
            ),
            StrategyKey::S3 => new AwsS3V3Adapter(
                client: new S3Client([
                    'credentials' => [
                        'key'    => $configs->get(S3Option::AccessKeyId),
                        'secret' => $configs->get(S3Option::SecretAccessKey)
                    ],
                    'endpoint' => $configs->get(S3Option::Endpoint),
                    'region' => $configs->get(S3Option::Region),
                    'version' => '2006-03-01',
                ]),
                bucket: $configs->get(S3Option::Bucket),
            ),
            StrategyKey::Oss => new OssAdapter(
                client: new OssClient($configs->get(
                    OssOption::AccessKeyId),
                    $configs->get(OssOption::AccessKeySecret),
                    $configs->get(OssOption::Endpoint),
                ),
                bucket: $configs->get(OssOption::Bucket),
            ),
            StrategyKey::Cos => new CosAdapter($configs->only([
                CosOption::AppId, CosOption::SecretId, CosOption::SecretKey, CosOption::Region, CosOption::Bucket,
            ])->toArray()),
            StrategyKey::Kodo => new QiniuAdapter(
                accessKey: $configs->get(KodoOption::AccessKey),
                secretKey: $configs->get(KodoOption::SecretKey),
                bucket: $configs->get(KodoOption::Bucket),
                domain: $configs->get(KodoOption::Url),
            ),
            StrategyKey::Uss => new UpyunAdapter(
                service: $configs->get(UssOption::Service),
                operator: $configs->get(UssOption::Operator),
                password: $configs->get(UssOption::Password),
                domain: $configs->get(UssOption::Url),
            ),
            StrategyKey::Sftp => new SftpAdapter(
                connectionProvider: new SftpConnectionProvider(
                    host: $configs->get(SftpOption::Host),
                    username: $configs->get(SftpOption::Username),
                    password: $configs->get(SftpOption::Password),
                    privateKey: $configs->get(SftpOption::PrivateKey),
                    passphrase: (string)$configs->get(SftpOption::Passphrase),
                    port: (int)$configs->get(SftpOption::Port),
                    useAgent: (bool)$configs->get(SftpOption::UseAgent)
                ),
                root: $configs->get(SftpOption::Root),
            ),
            StrategyKey::Ftp => new FtpAdapter(
                connectionOptions: FtpConnectionOptions::fromArray([
                    'host' => $configs->get(FtpOption::Host),
                    'root' => $configs->get(FtpOption::Root),
                    'port' => (int)$configs->get(FtpOption::Port),
                    'ssl' => (bool)$configs->get(FtpOption::Ssl),
                    'username' => $configs->get(FtpOption::Username),
                    'password' => $configs->get(FtpOption::Password),
                    'passive' => (bool)$configs->get(FtpOption::Passive),
                    'timeout' => 30,
                ]),
            ),
            StrategyKey::Webdav => new WebDAVAdapter(new Client([
                'baseUri' => $configs->get(WebDavOption::BaseUri),
                'userName' => $configs->get(WebDavOption::Username),
                'password' => $configs->get(WebDavOption::Password)
            ])),
            StrategyKey::Minio => new AwsS3V3Adapter(
                client: new S3Client([
                    'credentials' => [
                        'key'    => $configs->get(MinioOption::AccessKey),
                        'secret' => $configs->get(MinioOption::SecretKey)
                    ],
                    'endpoint' => $configs->get(MinioOption::Endpoint),
                    'region' => $configs->get(MinioOption::Region),
                    'version' => '2006-03-01',
                    'bucket_endpoint' => (bool)$configs->get(MinioOption::BucketEndpoint),
                ]),
                bucket: $configs->get(MinioOption::Bucket),
            ),
        };
    }

    /**
     * @throws UploadException
     */
    protected function rateLimiter(Collection $configs, Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        $carbon = Carbon::now();
        $array = [
            'minute' => ['key' => GroupConfigKey::LimitPerMinute, 'str' => '??????'],
            'hours' => ['key' => GroupConfigKey::LimitPerHour, 'str' => '??????'],
            'days' => ['key' => GroupConfigKey::LimitPerDay, 'str' => '???'],
            'weeks' => ['key' => GroupConfigKey::LimitPerWeek, 'str' => '???'],
            'months' => ['key' => GroupConfigKey::LimitPerMonth, 'str' => '???'],
        ];

        foreach ($array as $key => $item) {
            $value = $configs->get($item['key'], 0);
            $count = Image::query()->whereBetween('created_at', [
                $carbon->parse("-1 {$key}"), $carbon,
            ])->when(! is_null($user), function (Builder $builder) use ($user) {
                $builder->where('user_id', $user->id);
            }, function (Builder $builder) use ($request) {
                $builder->where('uploaded_ip', $request->ip())->whereNull('user_id');
            })->count();

            if ($value && $count >= $value) {
                throw new UploadException("???{$item['str']}???????????????????????? {$value} ?????????");
            }
        }
    }

    /**
     * ????????????????????????
     *
     * @param $driver
     * @param  Collection  $configs
     * @param  Image  $image
     * @param  UploadedFile $file
     * @return bool true=??????
     * @throws UploadException
     */
    public function scan($driver, Collection $configs, Image $image, UploadedFile $file): bool
    {
        $flag = false;
        try {
            if ($driver === 'tencent') {
                // ???????????????????????? 5mb
                if ($file->getSize() >= 5242880) {
                    return false;
                }

                $cred = new Credential($configs->get(TencentOption::SecretId), $configs->get(TencentOption::SecretKey));
                $httpProfile = new HttpProfile();
                $httpProfile->setEndpoint($configs->get(TencentOption::Endpoint));
                $clientProfile = new ClientProfile();
                $clientProfile->setHttpProfile($httpProfile);
                $client = new ImsClient($cred, $configs->get(TencentOption::Region), $clientProfile);
                $req = new ImageModerationRequest();
                $params = [
                    "FileContent" => base64_encode($file->getContent()),
                ];
                if ($configs->get(TencentOption::BizType)) {
                    $params['BizType'] = $configs->get(TencentOption::BizType);
                }
                $req->fromJsonString(json_encode($params));
                $resp = $client->ImageModeration($req);

                if ($resp->getSuggestion() === 'Block') {
                    $flag = true;
                }
            }

            if ($driver === 'aliyun') {
                // 20 mb???????????????????????? 30000px
                if ($file->getSize() >= 20971520 || $image->width >= 30000 || $image->height >= 30000) {
                    return false;
                }

                AlibabaCloud::accessKeyClient(
                    $configs->get(AliyunOption::AccessKeyId),
                    $configs->get(AliyunOption::AccessKeySecret),
                )->regionId($configs->get(AliyunOption::RegionId))->asDefaultClient();
                $task = ['dataId' => $image->id, 'url' => $image->url];
                $response = Green::v20180509()->imageSyncScan()->body(json_encode([
                    'tasks' => [$task],
                    'scenes' => $configs->get(AliyunOption::Scenes),
                    'bizType' => $configs->get(AliyunOption::BizType)
                ]))->request();
                if (200 != $response->get('code')) {
                    throw new \Exception("detect not success. code:" . $response->get('code'));
                }
                if ($result = $response->data[0] ?? null) {
                    if (200 == $result->code) {
                        foreach ($result->results as $item) {
                            if ($item->suggestion === 'block') {
                                $flag = true;
                            }
                        }
                    }
                }
            }

            if ($driver === 'nsfwjs') {
                // ????????? bmp ??????
                if ($image->extension === 'bmp') {
                    return false;
                }
                $response = Http::timeout(180)->withOptions(['timeout' => 180])->attach(
                    $configs->get(NsfwJsOption::AttrName, 'image'), $file->getContent(), $file->getClientOriginalName(),
                )->post($configs->get(NsfwJsOption::ApiUrl));
                $ratio = $configs->get(NsfwJsOption::Threshold, 60) / 100;

                if ($response->json('hentai', 0.00) >= $ratio) {
                    $flag = true;
                }

                if ($response->json('porn', 0.00) >= $ratio) {
                    $flag = true;
                }

                if ($response->json('sexy', 0.00) >= $ratio) {
                    $flag = true;
                }
            }
        } catch (\Throwable $e) {
            throw new UploadException('Scan: '.$e->getMessage());
        }

        return $flag;
    }

    /**
     * ????????????
     *
     * @param  mixed  $image
     * @param  Collection  $configs
     * @return \Intervention\Image\Image
     */
    public function stickWatermark(mixed $image, Collection $configs): \Intervention\Image\Image
    {
        $driver = $configs->get('driver');
        $options = collect($configs->get("drivers")[$driver]);
        $image = InterventionImage::make($image);

        $position = $options->get(FontOption::Position, 'bottom-right');
        $offsetX = (int) $options->get(FontOption::X, 10);
        $offsetY = (int) $options->get(FontOption::Y, 10);

        $watermark = $this->getWatermark($driver, $options);

        // ????????????
        $imageWidth = $image->width();
        $imageHeight = $image->height();

        // ??????????????????
        $watermarkWidth = $watermark->width();
        $watermarkHeight = $watermark->height();

        if ($position === 'tiled') {
            // ????????????
            for ($x = 0; $x < $imageWidth; $x++) {
                for ($y = 0; $y < $imageHeight; $y++) {
                    $image->insert($watermark, '', $x, $y);
                    $y += $watermarkHeight + $offsetY;
                }
                $x += $watermarkWidth + $offsetX;
            }
        } else {
            $image->insert($watermark, $position, $offsetX, $offsetY);
        }

        return $image;
    }

    /**
     * ?????????????????????????????????????????? public ????????????????????????????????????
     *
     * @param mixed $image ????????????
     * @param mixed $data ??????????????????
     * @param int $max ????????????
     * @param bool $force ??????????????????
     * @return void
     */
    public function makeThumbnail(Image $image, mixed $data, int $max = 400, bool $force = false): void
    {
        $pathname = public_path($image->getThumbnailPathname());

        if (! file_exists($pathname) || $force) {
            try {
                // ???????????????
                if (! is_dir(dirname($pathname))) {
                    @mkdir(dirname($pathname));
                }

                @ini_set('memory_limit', '512M');

                $img = InterventionImage::make($data);

                $width = $w = $image->width;
                $height = $h = $image->height;

                if ($w > $max && $h > $max) {
                    $scale = min($max / $w, $max / $h);
                    $width  = (int)($w * $scale);
                    $height = (int)($h * $scale);
                }

                $img->fit($width, $height, fn($constraint) => $constraint->upsize())->encode('png', 60)->save($pathname);
                $img->destroy();
            } catch (\Throwable $e) {
                Utils::e($e, '??????????????????????????????');
            }
        }
    }

    /**
     * ??????????????????
     *
     * @param  string  $driver
     * @param  Collection  $options
     * @return \Intervention\Image\Image
     */
    private function getWatermark(string $driver, Collection $options): \Intervention\Image\Image
    {
        $manager = new ImageManager(config('image'));

        if ($driver === 'image') {
            $watermark = $manager->make(storage_path('app/public/'.trim($options->get(ImageOption::Image), '/')));
            $opacity = (int) $options->get(ImageOption::Opacity, 0);
            $rotate = (int) $options->get(ImageOption::Rotate, 0);
            $width = $options->get(ImageOption::Width, 0);
            $height = $options->get(ImageOption::Height, 0);

            if ($opacity && $opacity != 100) {
                $watermark->opacity((int) min($opacity, 100));
            }

            if ($rotate) {
                $watermark->rotate($rotate);
            }

            if ($width + $height > 0) {
                $watermark->resize($width ?: null, $height ?: null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
            }
            return $watermark;
        } else {
            $text = $options->get(FontOption::Text, Utils::config(ConfigKey::AppName));
            $font = new Font(urldecode($text));
            $font->valign('top')
                ->file(storage_path('app/public/'.trim($options->get(FontOption::Font), '/')))
                ->size((int) $options->get(FontOption::Size, 50))
                ->angle((int) $options->get(FontOption::Angle, 0))
                ->color($options->get(FontOption::Color, '000000')); // ???????????? or rgba
            $box = $font->getBoxSize();
            $canvas = $manager->canvas($box['width'], $box['height']);
            $font->applyToImage($canvas);
            return $manager->make($canvas);
        }
    }

    protected function replacePathname(string $pathname, UploadedFile $file): string
    {
        $array = [
            '{Y}' => date('Y'),
            '{y}' => date('y'),
            '{m}' => date('m'),
            '{d}' => date('d'),
            '{timestamp}' => time(),
            '{uniqid}' => uniqid(),
            '{md5}' => md5(microtime().Str::random()),
            '{md5-16}' => substr(md5(microtime().Str::random()), 0, 16),
            '{str-random-16}' => Str::random(),
            '{str-random-10}' => Str::random(10),
            '{filename}' => Str::replaceLast('.'.$file->getClientOriginalExtension(), '', $file->getClientOriginalName()),
            '{uid}' => Auth::check() ? Auth::id() : 0,
        ];
        return str_replace(array_keys($array), array_values($array), $pathname);
    }
}
