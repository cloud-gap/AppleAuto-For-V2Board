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
    private $share_url = 'https://xx.xx.xx/shareapi/gVQvljbXiw/xxx';

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

        try {
            $stream_opts = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => [
                    'timeout' => 5,
                    'header' => [
                        'Content-Type: application/json',
                        'Accept: application/json, text/plain, */*'
                    ]
                ]
            ];

            $result = file_get_contents($this->share_url, false, stream_context_create($stream_opts));
            if ($result === false) {
                throw new Exception('获取失败,页面请求时出现错误');
            }

            $req = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('获取失败,JSON数据解析错误,请检查是否为shareapi');
            }

            if (!empty($req['status'])) {
                $accounts = $req['accounts'] ?? [];
                for ($i = 0; $i < sizeof($accounts); $i++) {
                    $body = str_replace("{{apple_id$i}}", $accounts[$i]['username'] ?? '', $body);
                    $body = str_replace("{{apple_pw$i}}", $accounts[$i]['password'] ?? '', $body);
                    $body = str_replace(
                        "{{apple_status$i}}",
                        !empty($accounts[$i]['status']) ? '正常' : '异常',
                        $body
                    );
                    $body = str_replace("{{apple_time$i}}", $accounts[$i]['last_check'] ?? '', $body);
                }
            } else {
                $msg = $req['msg'] ?? '未知错误';
                $body = str_replace('{{apple_id0}}', "获取失败,{$msg}", $body);
            }
        } catch (Exception $error) {
            // 兼容原实现：只写入第一个占位符作为报错提示
            $body = str_replace('{{apple_id0}}', $error->getMessage(), $body);
        }
    }
}
