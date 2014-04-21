<?php
/**
 * CommentToMail Plugin
 * 异步发送提醒邮件到博主或访客的邮箱
 * 
 * @copyright  Copyright (c) 2012 DEFE (http://defe.me)
 * @license    GNU General Public License 2.0
 *
 */

class CommentToMail_Action extends Typecho_Widget implements Widget_Interface_Do
{

    private $_db;
    private $_dir;
    private $_set;
    public  $mail;
    public  $smtp;

    public function  __construct($request, $response, $params = NULL) {
        parent::__construct($request, $response, $params);
        $this->_db = Typecho_Db::get();
        $this->_dir ='.'. __TYPECHO_PLUGIN_DIR__.'/CommentToMail/';
        $this->_set = Helper::options()->plugin('CommentToMail');

        require_once ($this->_dir.'class.phpmailer.php');
        $this->mail = new PHPMailer();
        $this->mail->CharSet = 'UTF-8';
        $this->mail->Encoding = 'base64';
    }

    /**
     * 读取缓存文件内容，并根据条件组合邮件内容发送。
     */
    public function send($cacheFile){
        $file = $this->_dir.'cache/'.$cacheFile;
        if(file_exists($file)){
            $this->smtp = unserialize(file_get_contents($this->_dir.'cache/'.$cacheFile));
            if(!$this->widget('Widget_User')->simpleLogin($this->smtp->ownerId)){
                @unlink($file);
                $this->widget('Widget_Archive@404', 'type=404')->render();
                exit;
            }
        } else {
            $this->widget('Widget_Archive@404', 'type=404')->render();
            exit;
        }
        

        //如果本次评论设置了拒收邮件，把coid加入拒收列表
        if($this->smtp->banMail){
            $this->proveParent($this->smtp->coid,1);
        }

        //选择发信模式
        switch ($this->_set->mode)
        {
            case 'mail':
                break;
            case 'sendmail':
                $this->mail->IsSendmail();
                break;
            case 'smtp':
                $this->mail->IsSMTP();
                if(in_array('validate',$this->_set->validate)) $this->mail->SMTPAuth   = true;
                if(in_array('ssl',$this->_set->validate))      $this->mail->SMTPSecure = "ssl";
                $this->mail->Host       = $this->_set->host;
                $this->mail->Port       = $this->_set->port;
                $this->mail->Username   = $this->_set->user;
                $this->mail->Password   = $this->_set->pass;
                $this->smtp->from       = $this->_set->user;
                break;
        }
       
        if(in_array('to_log',$this->_set->other)) $this->smtp->mailLog = true;

        //向博主发邮件的标题格式
        $this->smtp->titleForOwner = $this->_set->titleForOwner;
        
        //向访客发邮件的标题格式
        $this->smtp->titleForGuest = $this->_set->titleForGuest;

        //验证博主是否接收自己的邮件
        $toMe = (in_array('to_me', $this->_set->other) && $this->smtp->ownerId == $this->smtp->authorId) ? true : false;
        
        
        //向博主发信
        if( in_array($this->smtp->status, $this->_set->status) && in_array('to_owner', $this->_set->other) && ( $toMe || $this->smtp->ownerId != $this->smtp->authorId) && 0 == $this->smtp->parent ){
            if(empty($this->_set->mail)){
            	Typecho_Widget::widget('Widget_Users_Author@' . $this->smtp->cid, array('uid' => $this->smtp->ownerId))->to($user);
            	$this->smtp->to = $user->mail;
            }else{
                $this->smtp->to = $this->_set->mail;
            }

            $this->sendMail(0);
        }

        //向访客发信
        if(0 != $this->smtp->parent && 'approved' == $this->smtp->status && in_array('to_guest', $this->_set->other) && $this->proveParent($this->smtp->parent)){
           
            $original = $this->_db->fetchRow($this->_db->select('author', 'mail', 'text')
                    ->from('table.comments')
                    ->where('coid = ?', $this->smtp->parent));

            $toGuest = (!in_array('to_me', $this->_set->other) && $this->smtp->mail == $original['mail'] || $this->smtp->to == $original['mail']) ? false: true;
            
            if($toGuest){           
                $this->smtp->to = $original['mail'];
                $this->smtp->originalText = $original['text'];
                $this->smtp->originalAuthor = $original['author'];
                $this->sendMail(1);
            } 
        }

        @unlink($file);

    }
    
    /*
     * 生成邮件内容并发送
     * $sendto 为  0 发向博主;  1 发向访客
     */
    public function sendMail($sendto = 0){
    	$date = new Typecho_Date($this->smtp->created);
        $time = date('Y-m-d H:i:s', $date->timeStamp);

        if(!$sendto){
            $status = array(
                "approved" => '通过',
                "waiting"  => '待审',
                "spam"     => '垃圾'
            );
            $subject = $this->_set->titleForOwner;
            $body =  $this->getTemplet();
            $search = array('{site}','{title}','{author}','{ip}','{mail}','{permalink}','{manage}','{text}','{time}','{status}');
            $replace = array($this->smtp->site,$this->smtp->title,$this->smtp->author,$this->smtp->ip,$this->smtp->mail,$this->smtp->permalink,$this->smtp->manage,$this->smtp->text,$time,$status[$this->smtp->status]);
        }  else {
            $subject = $this->_set->titleForGuest;
            $body = $this->getTemplet(1);
            $search = array('{site}','{title}','{author_p}','{author}','{mail}','{permalink}','{text}','{text_p}','{time}');
            $replace = array($this->smtp->site,$this->smtp->title,$this->smtp->originalAuthor,$this->smtp->author, $this->smtp->mail,$this->smtp->permalink,$this->smtp->text,$this->smtp->originalText,$time);
        }

        $this->smtp->body = str_replace($search, $replace, $body);
        $this->smtp->subject = str_replace($search, $replace, $subject);
        $this->smtp->AltBody = "作者：".$this->smtp->author."\r\n链接：".$this->smtp->permalink."\r\n评论：\r\n".$this->smtp->text;
        
        $this->mail->SetFrom($this->smtp->from, $this->smtp->site);
        $this->mail->AddReplyTo($this->smtp->to, $this->smtp->site);
        $this->mail->Subject = $this->smtp->subject;
        $this->mail->AltBody = $this->smtp->AltBody;
        $this->mail->MsgHTML($this->smtp->body);

        $name = $this->smtp->originalAuthor ? $this->smtp->originalAuthor : $this->smtp->site;

        $this->mail->AddAddress($this->smtp->to,$name);
        
        if($this->mail->Send()){
            if(in_array('to_log', $this->_set->other)) $this->mailLog();
        }else{
            $this->mailLog(0);
        }
        $this->mail->ClearAddresses();
        $this->mail->ClearReplyTos();

    }


    /*
     * 记录邮件发送日志和错误信息
     */
    public function mailLog($type = 1){
        if($type){
            //file_put_contents($this->_dir.'log/log.txt', $msg);
            $msg = $msg ? $msg : date("Y-m-d H:i:s",$this->smtp->created+$this->smtp->timezone)." 向 ".  $this->smtp->to." 发送邮件成功！\r\n";
            $file = $this->_dir.'/log/mail_log.txt';
            $fp = @fopen($file,'a+');
            fwrite($fp,$msg);
            fclose($fp);
        }  else {
            file_put_contents($this->_dir.'log/error_log.txt', $this->mail->ErrorInfo);
        }
    }
    /*
     * 获取邮件正文模板
     * $og 0为博主 1为访客
     */
    public function getTemplet($og = 0){
        if(!$og){
            $templet = file_get_contents($this->_dir.'owner.html');
        }else{
            $templet = file_get_contents($this->_dir.'guest.html');
        }
        return $templet;
    }
    /*
     * 验证原评论者是否接收评论
     */
    public function proveParent($parent, $write = false){
        if($parent){
            $index = ceil($parent/500);
            $filename = $this->_dir.'log/ban_'.$index.'.list';

            if(!file_exists($filename)){
                file_put_contents($filename, "a:0:{}");
            }

            $list=unserialize(file_get_contents($filename));
            //写入记录
            if($write){
                $list[$parent]=1;
                file_put_contents($filename,serialize($list));
                return true;
            }
            //判读记录是否存在，存在则返回false，不存在返回true表示接收邮件
            if(!$write && 1 == $list[$parent]){
                return false;
            }else{
                return true;
            }

        } else {
            return false;
        }
    }
    
    public function action(){
        $this->on($this->request->is('send'))->send($this->request->send);
    }
}
?>

