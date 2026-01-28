<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Exception;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    // 在使用文档中显示共享 AppleID
    // 说明：前端变量 {{apple_idX}} {{apple_pwX}} {{apple_statusX}} {{apple_timeX}} （X 从 0 开始）
    // 参考文档：https://appleauto.pro/docs/api/v2board.html
    // 这里填写你的 AppleAutoPro shareapi 地址（如果分享页有密码也在该地址中体现；若没有请留空）
    private $share_url = 'https://test.com/shareapi/kfcv50';

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first()
                ->toArray();
            if (!$knowledge) abort(500, __('Article does not exist'));
            $user = User::find($request->user['id']);
            $userService = new UserService();
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(
                    array('+', '/', '='),
                    array('-', '_', ''),
                    base64_encode($subscribeUrl)
                ),
                $knowledge['body']
            );
            $knowledge['body'] = str_replace('{{subscribeToken}}', $user['token'], $knowledge['body']);

            // AppleAutoPro：将共享 AppleID 信息注入到知识库内容中
            $this->apple($knowledge['body']);

            return response([
                'data' => $knowledge
            ]);
        }
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');
        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        $knowledges = $builder->get()
            ->groupBy('category');
        return response([
            'data' => $knowledges
        ]);
    }

    private function getBetween($input, $start, $end)
    {
        $substr = substr($input, strlen($start) + strpos($input, $start), (strlen($input) - strpos($input, $end)) * (-1));
        return $start . $substr . $end;
    }

    private function formatAccessData(&$body)
    {
        while (strpos($body, '<!--access start-->') !== false) {
            $accessData = $this->getBetween($body, '<!--access start-->', '<!--access end-->');
            if ($accessData) {
                $body = str_replace($accessData, '<div class="v2board-no-access">'. __('You must have a valid subscription to view content in this area') .'</div>', $body);
            }
        }
    }

    /**
     * AppleAutoPro shareapi：替换知识库中的 AppleID 模板变量
     * {{apple_idX}} {{apple_pwX}} {{apple_statusX}} {{apple_timeX}}
     */
    private function apple(&$body)
    {
        // 未配置 share_url 时，不处理
        if (!$this->share_url) return;

        // 统一的错误清理：避免页面上残留 {{apple_xx}} 占位符
        $clearPlaceholders = function () use (&$body) {
            $body = preg_replace('/\{\{apple_(id|pw|status|time)\d+\}\}/', '', $body);
        };

        try {
            $result = null;

            // 优先使用 cURL（对 403/重定向/UA 更友好）
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->share_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json, text/plain, */*',
                        'Content-Type: application/json',
                        'User-Agent: CloudGap/1.0 (V2Board; AppleAutoPro)',
                    ],
                ]);
                $result = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($result === false || $httpCode < 200 || $httpCode >= 300) {
                    $msg = $curlErr ?: ('HTTP ' . $httpCode);
                    throw new Exception('shareapi 请求失败：' . $msg);
                }
            } else {
                // 兜底：file_get_contents（加 UA，并启用跟随跳转）
                $stream_opts = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                    'http' => [
                        'timeout' => 8,
                        'follow_location' => 1,
                        'ignore_errors' => true,
                        'header' => [
                            'Accept: application/json, text/plain, */*',
                            'Content-Type: application/json',
                            'User-Agent: CloudGap/1.0 (V2Board; AppleAutoPro)',
                        ],
                    ],
                ];

                $result = file_get_contents($this->share_url, false, stream_context_create($stream_opts));

                // 读取 HTTP 状态码（php wrapper 会把状态行放在 $http_response_header[0]）
                $statusLine = isset($http_response_header[0]) ? $http_response_header[0] : '';
                if (!preg_match('/\s(\d{3})\s/', $statusLine, $m2)) {
                    throw new Exception('shareapi 请求失败：无法解析状态码');
                }
                $httpCode = (int)$m2[1];

                if ($result === false || $httpCode < 200 || $httpCode >= 300) {
                    throw new Exception('shareapi 请求失败：' . $statusLine);
                }
            }

                        $req = json_decode($result, true);
            if (!is_array($req)) {
                throw new Exception('shareapi 返回不是有效 JSON');
            }

            // 兼容多种返回格式：
            // 1) CloudGap/自建：{"code":200,"msg":"获取成功","accounts":[...],"status":true}
            // 2) AppleAutoPro 旧格式：{"data":{"list":[...]}}
            if (isset($req['accounts']) && is_array($req['accounts'])) {
                $apple_ids = $req['accounts'];
            } else if (isset($req['data']['list']) && is_array($req['data']['list'])) {
                $apple_ids = $req['data']['list'];
            } else if (isset($req['data']) && is_array($req['data'])) {
                // 少数情况下 data 直接就是数组
                $apple_ids = $req['data'];
            } else {
                throw new Exception('shareapi 返回结构不匹配：未找到 accounts 或 data.list');
            }

            // 替换占位符：{{apple_id0}} {{apple_pw0}} {{apple_status0}} {{apple_time0}} ...
            foreach ($apple_ids as $k => $v) {
                $index = (int)$k;

                // 兼容字段命名
                $id = $v['username'] ?? ($v['account'] ?? '');
                $pw = $v['password'] ?? '';
                // 状态优先用 message（如“正常/风控/需要验证”），否则根据布尔值推断
                $statusText = $v['message'] ?? ($v['status'] ?? '');
                if (is_bool($statusText)) {
                    $statusText = $statusText ? '正常' : '异常';
                } else if ($statusText === '' && isset($v['status']) && is_bool($v['status'])) {
                    $statusText = $v['status'] ? '正常' : '异常';
                }

                $time = $v['last_check'] ?? ($v['updated_at'] ?? ($v['time'] ?? ''));

                $body = str_replace('{{apple_id' . $index . '}}', (string)$id, $body);
                $body = str_replace('{{apple_pw' . $index . '}}', (string)$pw, $body);
                $body = str_replace('{{apple_status' . $index . '}}', (string)$statusText, $body);
                $body = str_replace('{{apple_time' . $index . '}}', (string)$time, $body);
            }

            // 把没替换到的占位符清空（避免显示 {{apple_pw1}} 这种）
            $clearPlaceholders();

        } catch (Exception $error) {
            // 显示错误在第一个账号位置，并清空剩余占位符
            $body = str_replace('{{apple_id0}}', $error->getMessage(), $body);
            $clearPlaceholders();
        }
    }
}
