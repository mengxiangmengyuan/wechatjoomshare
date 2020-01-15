<?php

/**
 * @package    weixinshare
 *
 * @author     mxmy <24739983#qq.com>
 * @copyright  www.mengxiangmengyuan.com
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       github.com/mengxiangmengyuan
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

/**
 * Weixinshare plugin.
 *
 * @package  [PACKAGE_NAME]
 * @since    1.0
 */
class plgSystemWeixinshare extends CMSPlugin
{
	/**
	 * Application object
	 *
	 * @var    CMSApplication
	 * @since  1.0
	 */
	protected $app;

	/**
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 * @since  1.0
	 */
	protected $db;

	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  1.0
	 */
	protected $autoloadLanguage = true;


	public function onBeforeCompileHead()
	{
		if ($this->app->isSite() == false) {
			return;
		}

		$appId = $this->params->get('appid');
		$appSecrect = $this->params->get('appsecret');

		if (empty($appId) || empty($appSecrect)) {
			return;
		}

		$wximg = JUri::base() . $this->params->get('wximg');

		$doc = $this->app->getDocument();

		//$doc->addScriptDeclaration($wximg);

		$doc->addScript('http://res.wx.qq.com/open/js/jweixin-1.4.0.js');

		$share_link = JUri::getInstance()->toString();
		$share_title = $doc->getTitle();
		$share_desc = empty($doc->getDescription()) ? $share_title : $doc->getDescription();

		$signPackage = $this->getSign($share_link, $appId, $appSecrect);
		if (!$signPackage) {
			return;
		}


		$jscontent = "

		wxShareData = {
			title: '$share_title',
			desc: '$share_desc',
			link: '$share_link',
			imgUrl: '$wximg'
		  };

		wx.config({
            debug: false,
            appId: '$appId',
            timestamp: '$signPackage->timestamp',
            nonceStr: '$signPackage->nonceStr',
            signature: '$signPackage->signature',
            jsApiList: ['checkJsApi','onMenuShareTimeline','onMenuShareAppMessage','onMenuShareQQ','onMenuShareWeibo','updateAppMessageShareData','updateTimelineShareData']
        });

        wx.ready(function () {

			wx.checkJsApi({
				jsApiList: ['onMenuShareAppMessage'],
				success: function(res) {
				  wx.onMenuShareAppMessage(wxShareData);
				  wx.onMenuShareTimeline(wxShareData);
				  wx.onMenuShareQQ(wxShareData);
				  wx.onMenuShareWeibo(wxShareData);
				}
			  });


           });
		";

		$doc->addScriptDeclaration($jscontent);
	}


	/**
	 * 获取JS用签名等相关信息.
	 */
	private function getSign($url, $appId, $appSecrect)
	{
		$jsapiTicket = $this->getJSTicketFromRemote($appId, $appSecrect);
		if (!$jsapiTicket) {
			return false;
		}

		$timestamp = time();
		$nonceStr = $this->getNonceString();

		// 这里参数的顺序要按照 key 值 ASCII 码升序排序
		$signStr = "jsapi_ticket=$jsapiTicket&noncestr=$nonceStr&timestamp=$timestamp&url=$url";

		$signature = sha1($signStr);

		$signPackage = new stdClass;
		$signPackage->nonceStr = $nonceStr;
		$signPackage->timestamp = $timestamp;
		$signPackage->signature = $signature;

		return $signPackage;
	}

	/**
	 * 通过远程接口设置Access Token.
	 */
	private function getAccessTokenFromRemote($appId, $appSecrect)
	{
		//先从本地缓存读取access_token
		$cachetoken = $this->get_php_file('access_token.php');
		if ($cachetoken) {
			if ($cachetoken->expire_time > time() && isset($cachetoken->access_token)) {
				//$this->set_php_file("data_access.php", "get token from local");
				return $cachetoken->access_token;
			}
		}

		// 从远程接口获取
		$url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&' .
			'appid=' . $appId .
			'&secret=' . $appSecrect;

		$result = $this->doCurl($url);

		if ($result) {
			$j = json_decode($result);

			if (isset($j->access_token)) {

				//把token写入缓存数据
				$data = new stdClass;
				$data->expire_time = time() + 6000;
				$data->access_token = $j->access_token;
				$this->set_php_file("access_token.php", json_encode($data));

				return $j->access_token;
			}
		}
		return false;
	}

	/**
	 * 设置JS Ticket.
	 */
	private function getJSTicketFromRemote($appId, $appSecrect)
	{
		//先从本地缓存读取ticket
		$cacheticket = $this->get_php_file('jsapi_ticket.php');
		if ($cacheticket) {
			if ($cacheticket->expire_time > time() && isset($cacheticket->ticket)) {
				$this->set_php_file("data_ticket.php", "get ticket from local");
				return $cacheticket->ticket;
			}
		}


		$tmpToken = $this->getAccessTokenFromRemote($appId, $appSecrect);

		if (!$tmpToken)
			return false;

		// 从远程接口获取
		$url = 'https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=' . $tmpToken . '&type=jsapi';

		$result = $this->doCurl($url);

		if ($result) {
			$j = json_decode($result);
			if (isset($j->errcode) && $j->errcode == '0') {
				//把token写入缓存数据
				$data = new stdClass;
				$data->expire_time = time() + 6000;
				$data->ticket = $j->ticket;
				$this->set_php_file("jsapi_ticket.php", json_encode($data));

				return $j->ticket;
			}
		}
		return false;
	}

	/**
	 * 获取随机数.
	 */
	private function getNonceString($length = 16)
	{
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$str = '';
		$len = strlen($chars);
		for ($i = 0; $i < $length; ++$i) {
			$str .= substr($chars, mt_rand(0, $len - 1), 1);
		}

		return $str;
	}

	/**
	 * 包一下curl get请求
	 */
	private function doCurl($url)
	{

		$httpOption = new Registry;
		$httpOption->set('userAgent', 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36 SE 2.X MetaSr 1.0');

		$http = HttpFactory::getHttp($httpOption);

		$response = $http->get($url);

		return $response->body;
	}

	/**
	 * 包一下读文件请求
	 */
	private function get_php_file($filename)
	{
		$plgfile = JPATH_PLUGINS . '/system/weixinshare/' . $filename;
		if (!JFile::exists($plgfile)) {
			return false;
		}
		return json_decode(trim(substr(JFile::read($plgfile), 15)));
	}

	/**
	 * 包一下写文件请求
	 */
	private function set_php_file($filename, $content)
	{
		$plgfile = JPATH_PLUGINS . '/system/weixinshare/' . $filename;
		JFile::write($plgfile, "<?php exit();?>" . $content);;
	}
}
