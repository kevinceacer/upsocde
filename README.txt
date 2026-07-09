UPS 查询会员、余额、购买、卡密和支付宝当面付系统
================================================

一、功能
--------
1. 用户注册、登录、退出。
2. 个人中心显示余额、余额明细、已经购买的完整UPS单号。
3. 前台可按国家、城市、州、妥投时间查询。
4. 未购买时只显示脱敏单号，购买后永久显示完整单号。
5. 后台可设置每条完整单号价格。
6. 卡密充值：后台批量生成，用户兑换后余额立即到账。
7. 支付宝当面付：在充值页面直接显示二维码，异步通知验签，主动查询补偿。
8. 后台用户余额调整、充值订单、购买记录。
9. db.php 保持兼容 Python 的 init/save/export，且只按 tracking_number 去重。

二、安装
--------
1. 把全部文件上传到网站目录。
2. 修改 config.php 的数据库名、用户名和密码。
3. 确保 PHP 已启用：
   - PDO MySQL
   - OpenSSL
   - cURL
   - mbstring
4. 浏览器打开：
   https://你的域名/install.php
5. 创建第一个管理员。
6. 安装完成后删除或重命名 install.php。

三、Python API
--------------
Python 中保持：
DB_API_URL = "https://你的域名/db.php"

若 config.php 的 api_key 留空，不需要修改 Python。
如果启用了 api_key，则 Python 每次请求也要发送 api_key。

四、支付宝当面付
----------------
后台路径：
/admin/settings.php

填写：
1. 支付宝开放平台 App ID
2. 应用私钥
3. 支付宝公钥（不是应用公钥）
4. 打开“开启支付宝当面付”
5. 把后台显示的异步通知地址配置到应用或确保公网 HTTPS 可访问

默认正式网关：
https://openapi.alipay.com/gateway.do

系统使用：
- alipay.trade.precreate 创建付款二维码
- /recharge.php 在当前页面直接生成并显示二维码
- 支付异步通知 RSA2 验签
- alipay.trade.query 主动查询补偿
- 订单事务和幂等处理，重复通知不会重复加余额
- 密钥保存后后台显示遮罩，不回显真实密钥

注意：
- alipay_create.php 是 JSON 接口，浏览器直接打开会跳回充值页面。
- alipay_pay.php 和 alipay_settings.php 仅保留为旧地址跳转。
- 支付配置统一存放在原系统 settings 表中，不再使用独立 alipay_config 表。

五、购买规则
------------
后台设置 tracking_price。
用户购买时余额会在数据库事务中扣除。
同一用户对同一 tracking_id 只能购买一次。
未购买只显示脱敏单号；购买后在查询页和个人中心显示完整单号。

六、安全建议
------------
1. 强制全站 HTTPS。
2. 安装后删除 install.php。
3. 不要更改 config.php 的 app_secret，否则已保存的支付宝密钥无法解密。
4. 禁止 Web 直接下载 config.php、lib.php。
5. 定期备份数据库。
6. 支付宝上线前先在沙箱或小额环境完整测试。
