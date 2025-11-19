<?php

/*
 * This file is part of fof/filter.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\Filter\Listener;

use Flarum\Flags\Event\Created;
use Flarum\Flags\Flag;
use Flarum\Post\Event\Saving;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Symfony\Contracts\Translation\TranslatorInterface;
use AlibabaCloud\SDK\Green\V20220302\Green;
use AlibabaCloud\SDK\Green\V20220302\Models\TextModerationRequest;
use AlibabaCloud\Tea\Utils\Utils\RuntimeOptions;
use Darabonba\OpenApi\Models\Config;
use AlibabaCloud\Tea\Utils\Utils;
use Exception;
use AlibabaCloud\Tea\Exception\TeaError;
use FoF\Filter\Model\CheckLog;
use Illuminate\Support\Arr;

class CheckPost
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var Mailer
     */
    protected $mailer;

    /**
     * @var Dispatcher
     */
    protected $bus;

    public function __construct(SettingsRepositoryInterface $settings, TranslatorInterface $translator, Mailer $mailer, Dispatcher $bus)
    {
        $this->settings = $settings;
        $this->translator = $translator;
        $this->mailer = $mailer;
        $this->bus = $bus;
    }

    public function handle(Saving $event)
    {
        $post = $event->post;
        $data = $event->data;

        //防止点赞重新触发审核
        if ($post->exists && isset($data['attributes']['isLiked'])){
            // $actor = $event->actor;
            // app("log")->info($actor->id." like");
            return;
        }
        if ($post->exists && Arr::has($data, 'attributes.reaction')) {
            return;
        }
        
        if ($post->auto_mod || $event->actor->can('bypassFoFFilter', $post->discussion)) {
            return;
        }

        // 优先使用阿里云检测，如果阿里云检测正常，则跳过本地敏感词检测
        $aliyunCheckEnabled = (bool) $this->settings->get('hamcq-filter.aliyun-content-check');
        $aliyunCheckPassed = false;
        
        if($aliyunCheckEnabled){
            $aliyunCheckPassed = $this->aliyunCheck($post);
            // 如果阿里云检测通过（返回true），则不再执行本地敏感词检测
            if($aliyunCheckPassed === true){
                return;
            }
        }

        // 如果阿里云检测未启用或检测失败，则执行本地敏感词检测
        if ($this->checkContent($post->content)) {
            $this->flagPost($post);

            if ((bool) $this->settings->get('fof-filter.emailWhenFlagged') && $post->emailed == 0) {
                $this->sendEmail($post);
            }
        }
    }

    public function checkContent($postContent): bool
    {
        $censors = json_decode($this->settings->get('fof-filter.censors'), true);

        $isExplicit = false;

        preg_replace_callback(
            $censors,
            function ($matches) use (&$isExplicit) {
                if ($matches) {
                    $isExplicit = true;
                }
            },
            str_replace(' ', '', $postContent)
        );

        return $isExplicit;
    }

    public function flagPost(Post $post): void
    {
        $post->is_approved = false;
        $post->auto_mod = true;
        $post->afterSave(function ($post) {
            if ($post->number == 1) {
                $post->discussion->is_approved = false;
                $post->discussion->save();
            }

            $flag = new Flag();
            $flag->post_id = $post->id;
            $flag->type = 'autoMod';
            $flag->reason_detail = $this->translator->trans('fof-filter.forum.flag_message');
            $flag->created_at = time();
            $flag->save();

            $this->bus->dispatch(new Created($flag, new Guest()));
        });
    }

    public function sendEmail($post): void
    {
        // Admin hasn't saved an email template to the database
        if (empty($this->settings->get('fof-filter.flaggedSubject'))) {
            $this->settings->set(
                'fof-filter.flaggedSubject',
                $this->translator->trans('fof-filter.admin.email.default_subject')
            );
        }

        if (empty($this->settings->get('fof-filter.flaggedEmail'))) {
            $this->settings->set(
                'fof-filter.flaggedEmail',
                $this->translator->trans('fof-filter.admin.email.default_text')
            );
        }

        $email = $post->user->email;
        $linebreaks = ["\n", "\r\n"];
        $subject = $this->settings->get('fof-filter.flaggedSubject');
        $text = str_replace($linebreaks, $post->user->username, $this->settings->get('fof-filter.flaggedEmail'));
        $this->mailer->send(
            'fof-filter::default',
            ['text' => $text],
            function (Message $message) use ($subject, $email) {
                $message->to($email);
                $message->subject($subject);
            }
        );
        $post->emailed = true;
    }

    //aliyunCheck 阿里云检测服务
    //https://help.aliyun.com/document_detail/433945.html?spm=a2c4g.434034.0.0.42ac1647svYZWW
    // 返回 true 表示检测通过（无违规），false 表示检测失败或需要进一步处理
    public function aliyunCheck(Post $post){
        $access_id = $this->settings->get('hamcq-filter.aliyun-content-check.access_id');
        $access_sec = $this->settings->get('hamcq-filter.aliyun-content-check.access_sec');
        $skip_lable_stirng = $this->settings->get('hamcq-filter.aliyun-content-check.skip_label');
        $skip_lable = explode(",", $skip_lable_stirng);
        if(!$access_id || !$access_sec){
            return false; // 配置不完整，返回false以执行本地检测
        }
        $content = "title:".$post->discussion->title.",content:".$post->content;
        $arr = array('content' => $content, "accountId"=>$post->user_id);
        $request = new TextModerationRequest();
        $request->service = "comment_detection";
        $request->serviceParameters = json_encode($arr);
        if (empty($arr) || empty(trim($arr["content"]))) {
            return false; // 内容为空，返回false以执行本地检测
        }
        $config = new Config([
            "accessKeyId" => $access_id,
            "accessKeySecret" => $access_sec,
            "endpoint" => "green-cip.cn-shanghai.aliyuncs.com",
            "regionId" => "cn-shanghai"
        ]);
        $client = new Green($config);
        $runtime = new RuntimeOptions([]);
        $runtime->readTimeout = 10000;
        $runtime->connectTimeout = 10000;
        if(mb_strlen($content)>600){
            return $this->aliyunCheckLongText($post, $client, $content,$skip_lable);
        }
        try {
            $response = $client->textModerationWithOptions($request, $runtime);
            if (Utils::equalNumber(500, $response->statusCode) || Utils::equalNumber(500, $response->body->code)) {
                $config->endpoint = "green-cip.cn-beijing.aliyuncs.com";
                $config->regionId = "cn-beijing";
                $client = new Green($config);
                $response = $client->textModerationWithOptions($request, $runtime);
            }
            app('log')->info( "discussion_id:".$post->discussion_id.",user_id:".$post->user_id.",result:". json_encode($response->body) );
            // 如果 HTTP 状态码是 200，说明 API 调用成功
            if(Utils::equalNumber(200, $response->statusCode)){
                // 如果 data 存在，检查 labels
                if($response->body->data){
                    $data = $response->body->data->toMap();
                    // 如果 labels 存在且不为空，说明检测到违规
                    if( isset($data["labels"])&& $data["labels"] != "" ){
                        // 如果标签在跳过列表中，则视为通过
                        if(count($skip_lable) > 0 && in_array($data["labels"],$skip_lable)){
                            return true; // 在跳过列表中，视为通过
                        }
                        // 检测到违规，标记帖子
                        $this->flagPostForAliyun($post, $data["labels"], json_encode($response->body));
                        if ((bool) $this->settings->get('fof-filter.emailWhenFlagged') && $post->emailed == 0) {
                            $this->sendEmail($post);
                        }
                        return false; // 检测到违规，返回false
                    }
                    // labels 不存在或为空，说明检测通过（无违规）
                    return true; // 检测通过，无违规
                } else {
                    // data 不存在，通常也意味着检测通过（无违规）
                    return true; // 检测通过，无违规
                }
            }
            // HTTP 状态码不是 200，记录错误并返回 false
            if(isset($response->body->message)){
                app('log')->error( $response->body->message );
            }
            if(isset($response->body->requestId)){
                app('log')->error( $response->body->requestId );
            }
            return false; // API调用失败，返回false以执行本地检测

        } catch (Exception $e) {
            if (!($e instanceof TeaError)) {
                $error = new TeaError([], $e->getMessage(), $e->getCode(), $e);
            }
            app('log')->error(Utils::assertAsString($error->message));
            return false; // 异常情况，返回false以执行本地检测
        }   
    }

    //aliyunCheckLongText 阿里云接口限制检测文本最大600字符
    // 返回 true 表示检测通过（无违规），false 表示检测失败或需要进一步处理
    public function aliyunCheckLongText(Post $post, $client, $content,$skip_lable)
    {
        $len = mb_strlen($content);
        for($i=0;$i<$len;$i+=600){
            $temp = mb_substr($content, $i ,600);
            $arr = array('content' => $temp, "accountId"=>$post->user_id);
            $request = new TextModerationRequest();
            $request->service = "comment_detection";
            $request->serviceParameters = json_encode($arr);
            $runtime = new RuntimeOptions([]);
            $runtime->readTimeout = 10000;
            $runtime->connectTimeout = 10000;
            try {
                $response = $client->textModerationWithOptions($request, $runtime);
                app('log')->info( "aliyunCheckLongText,discussion_id:".$post->discussion_id.",user_id:".$post->user_id.",result:". json_encode($response->body) );
                // 如果 HTTP 状态码是 200，说明 API 调用成功
                if(Utils::equalNumber(200, $response->statusCode)){
                    // 如果 data 存在，检查 labels
                    if($response->body->data){
                        $data = $response->body->data->toMap();
                        // 如果 labels 存在且不为空，说明检测到违规
                        if( isset($data["labels"])&& $data["labels"] != "" ){
                            // 如果标签在跳过列表中，则继续检测下一段
                            if(count($skip_lable) > 0 && in_array($data["labels"],$skip_lable)){
                                continue; // 在跳过列表中，继续检测下一段
                            }
                            // 检测到违规，标记帖子
                            $this->flagPostForAliyun($post, $data["labels"], json_encode($response->body));
                            if ((bool) $this->settings->get('fof-filter.emailWhenFlagged') && $post->emailed == 0) {
                                $this->sendEmail($post);
                            }
                            return false; // 检测到违规，返回false
                        }
                        // labels 不存在或为空，说明这段检测通过，继续检测下一段
                    }
                    // data 不存在或 labels 为空，说明这段检测通过，继续检测下一段
                }else{
                    // HTTP 状态码不是 200，记录错误并返回 false
                    if(isset($response->body->message)){
                        app('log')->error( $response->body->message );
                    }
                    if(isset($response->body->requestId)){
                        app('log')->error( $response->body->requestId );
                    }
                    // API调用失败，返回false以执行本地检测
                    return false;
                }
            }
            catch (Exception $e) {
                if (!($e instanceof TeaError)) {
                    $error = new TeaError([], $e->getMessage(), $e->getCode(), $e);
                }
                app('log')->error(Utils::assertAsString($error->message));
                // 异常情况，返回false以执行本地检测
                return false;
            }   
        }
        // 所有分段都检测通过，返回true
        return true;
    }

    public function flagPostForAliyun(Post $post, $lables, $checkRes): void
    {
        $post->is_approved = false;
        $post->auto_mod = true;
        $post->afterSave(function ($post) use ($lables, $checkRes) {
            if ($post->number == 1) {
                $post->discussion->is_approved = false;
                $post->discussion->save();
            }

            $flag = new Flag();
            $flag->post_id = $post->id;
            $flag->type = 'aliyunCheck';
            $flag->reason_detail = $lables;
            $flag->created_at = time();
            $flag->save();

            $this->bus->dispatch(new Created($flag, new Guest()));

            CheckLog::insert([
                "user_id" => $post->user_id,
                "discussion_id" => $post->discussion->id,
                "post_id" => $post->id,
                "result" => $checkRes,
                "created_time" => time()
            ]);
        });
    }

}
