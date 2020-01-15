# wechatjoomshare
微信分享接口调用

官方文档地址：https://developers.weixin.qq.com/doc/offiaccount/OA_Web_Apps/JS-SDK.html
最重要的是获取```wx.config```中的```timestamp，nonceStr,signature```三个参数

## 获取步骤如下：

### 1. 获取 AccessToken

参考以下文档获取access_token（有效期7200秒，通过文件存储缓存access_token）：
https://developers.weixin.qq.com/doc/offiaccount/Basic_Information/Get_access_token.html

本插件中的函数为```getAccessTokenFromRemote```


### 2. 获取 jsapi_ticket

用第一步拿到的 *access_token* 采用*http GET*方式请求获得*jsapi_ticket*（有效期7200秒，通过文件存储缓存access_token）：
https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=ACCESS_TOKEN&type=jsapi

### 3. 根据算法生成签名

用到的参数：
- 参与签名的字段包括noncestr（随机字符串）,通过```getNonceString```获取
- 有效的jsapi_ticket, timestamp（时间戳）
- url（当前网页的URL，不包含#及其后面部分）

按jsapi_ticket，noncestr，timestamp，url的顺序（ASCII 码从小到大排序）组成字符串，对字符串作sha1加密，生成signature

### 4.生成js文件

- 将 *timestamp，nonceStr,signature* 填入```wx.config```中。
- 配置分享的*title，desc,link,imgUrl* 。
- 通过```wx.checkJsApi```返回成功后，调用```onMenuShareAppMessage、onMenuShareTimeline```等分享接口。