<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 首页介绍页
|--------------------------------------------------------------------------
| 兼容现有会员系统：
| - 如果同目录存在 lib.php，会自动读取登录状态和网站名称
| - 未登录时右上角显示“登录 / 注册”
| - 已登录时显示“查询 / 个人中心 / 余额充值 / 退出”
*/

$hasMemberSystem = is_file(__DIR__ . '/lib.php');

if ($hasMemberSystem) {
    require_once __DIR__ . '/lib.php';

    try {
        installTables();
    } catch (Throwable $e) {
        // 首页不因自动建表失败而中断。
    }

    $user = currentUser();
    $siteName = setting('site_name', 'TrackHub');
} else {
    $user = null;
    $siteName = 'TrackHub';
}

function homeUrl(string $path): string
{
    if (function_exists('basePath')) {
        return basePath($path);
    }

    return $path;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$loginUrl = homeUrl('login.php');
$registerUrl = homeUrl('register.php');
$queryUrl = homeUrl('ups.php');
$profileUrl = homeUrl('profile.php');
$rechargeUrl = homeUrl('recharge.php');
$logoutUrl = homeUrl('logout.php');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07111f">
<title><?= e($siteName) ?> - 专业UPS物流单号查询平台</title>
<meta
    name="description"
    content="按国家、城市、州和时间快速筛选物流信息，注册登录后可充值并购买完整单号。"
>
<style>
:root{
    --bg:#f5f7fb;
    --card:#ffffff;
    --text:#101828;
    --muted:#667085;
    --line:#e4e7ec;
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --navy:#07111f;
    --green:#12b76a;
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",
        Arial,"Microsoft YaHei",sans-serif;
    -webkit-font-smoothing:antialiased;
}
a{text-decoration:none}
.container{
    width:min(1200px,calc(100% - 32px));
    margin:0 auto;
}
.site-header{
    position:absolute;
    top:0;
    left:0;
    right:0;
    z-index:50;
}
.header-inner{
    min-height:78px;
    display:flex;
    align-items:center;
    gap:24px;
}
.logo{
    display:inline-flex;
    align-items:center;
    gap:11px;
    color:#fff;
    font-size:20px;
    font-weight:850;
    letter-spacing:-.5px;
}
.logo-mark{
    width:42px;
    height:42px;
    display:grid;
    place-items:center;
    border-radius:14px;
    color:#fff;
    background:linear-gradient(145deg,#3b82f6,#6366f1);
    box-shadow:0 12px 30px rgba(37,99,235,.32);
}
.logo-mark svg{width:23px;height:23px}
.main-nav{
    display:flex;
    align-items:center;
    gap:6px;
    margin-left:auto;
}
.main-nav a{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:41px;
    padding:9px 14px;
    border-radius:10px;
    color:#dbe7f6;
    font-size:14px;
    font-weight:700;
    transition:.2s ease;
}
.main-nav a:hover{
    color:#fff;
    background:rgba(255,255,255,.09);
}
.main-nav .login-btn{
    border:1px solid rgba(255,255,255,.25);
}
.main-nav .register-btn{
    color:#0f172a;
    background:#fff;
    box-shadow:0 8px 22px rgba(0,0,0,.16);
}
.main-nav .register-btn:hover{
    color:#0f172a;
    background:#eff6ff;
}
.hero{
    position:relative;
    overflow:hidden;
    min-height:720px;
    padding:155px 0 110px;
    color:#fff;
    background:
        radial-gradient(circle at 80% 24%,rgba(59,130,246,.38),transparent 27%),
        radial-gradient(circle at 16% 86%,rgba(79,70,229,.34),transparent 30%),
        linear-gradient(135deg,#06101c 0%,#0d2242 55%,#172554 100%);
}
.hero:before{
    content:"";
    position:absolute;
    inset:0;
    opacity:.2;
    background-image:
        linear-gradient(rgba(255,255,255,.08) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px);
    background-size:48px 48px;
    mask-image:linear-gradient(to bottom,#000,transparent 82%);
}
.hero-inner{
    position:relative;
    z-index:2;
    display:grid;
    grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr);
    align-items:center;
    gap:60px;
}
.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:9px;
    padding:8px 13px;
    border:1px solid rgba(255,255,255,.18);
    border-radius:999px;
    color:#dbeafe;
    background:rgba(255,255,255,.08);
    font-size:14px;
    font-weight:750;
}
.hero-badge i{
    width:8px;
    height:8px;
    border-radius:50%;
    background:#34d399;
    box-shadow:0 0 0 5px rgba(52,211,153,.15);
}
.hero h1{
    max-width:780px;
    margin:22px 0 20px;
    font-size:clamp(43px,5vw,72px);
    line-height:1.06;
    letter-spacing:-2.8px;
}
.hero h1 span{color:#93c5fd}
.hero-desc{
    max-width:700px;
    margin:0 0 31px;
    color:#cbd5e1;
    font-size:18px;
    line-height:1.85;
}
.hero-actions{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}
.hero-actions a{
    min-height:51px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:13px 21px;
    border-radius:12px;
    font-weight:850;
    transition:.22s ease;
}
.hero-actions a:hover{transform:translateY(-2px)}
.primary-action{
    color:#0f172a;
    background:#fff;
    box-shadow:0 13px 32px rgba(0,0,0,.22);
}
.secondary-action{
    color:#fff;
    border:1px solid rgba(255,255,255,.24);
    background:rgba(255,255,255,.08);
}
.preview-card{
    padding:25px;
    border:1px solid rgba(255,255,255,.16);
    border-radius:23px;
    background:rgba(255,255,255,.09);
    backdrop-filter:blur(15px);
    box-shadow:0 25px 80px rgba(0,0,0,.3);
}
.preview-head{
    display:flex;
    justify-content:space-between;
    gap:18px;
    margin-bottom:20px;
}
.preview-title{font-weight:850}
.status-badge{
    padding:6px 10px;
    border-radius:999px;
    color:#a7f3d0;
    background:rgba(16,185,129,.15);
    font-size:12px;
    font-weight:800;
}
.track-panel{
    padding:20px;
    border:1px solid rgba(255,255,255,.1);
    border-radius:16px;
    background:rgba(2,6,23,.43);
}
.track-number{
    font-family:monospace;
    font-size:20px;
    letter-spacing:1.6px;
}
.route{
    display:flex;
    align-items:center;
    gap:10px;
    margin:23px 0 20px;
}
.route span:first-child,
.route span:last-child{
    width:13px;
    height:13px;
    border:3px solid #60a5fa;
    border-radius:50%;
}
.route span:last-child{border-color:#34d399}
.route-line{
    height:2px;
    flex:1;
    background:linear-gradient(90deg,#60a5fa,#34d399);
}
.preview-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
}
.preview-item{
    padding:13px;
    border-radius:12px;
    background:rgba(255,255,255,.07);
}
.preview-item strong{
    display:block;
    margin-bottom:5px;
    font-size:17px;
}
.preview-item small{color:#cbd5e1}
.section{
    padding:85px 0;
}
.section-head{
    max-width:780px;
    margin:0 auto 45px;
    text-align:center;
}
.kicker{
    color:var(--primary);
    font-size:13px;
    font-weight:900;
    letter-spacing:2px;
    text-transform:uppercase;
}
.section-head h2{
    margin:11px 0 13px;
    font-size:clamp(31px,4vw,46px);
    letter-spacing:-1.3px;
}
.section-head p{
    margin:0;
    color:var(--muted);
    line-height:1.8;
    font-size:16px;
}
.feature-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:20px;
}
.feature-card{
    padding:27px;
    border:1px solid var(--line);
    border-radius:19px;
    background:var(--card);
    box-shadow:0 10px 35px rgba(16,24,40,.055);
    transition:.23s ease;
}
.feature-card:hover{
    transform:translateY(-6px);
    border-color:#bfdbfe;
    box-shadow:0 19px 45px rgba(37,99,235,.12);
}
.feature-icon{
    width:54px;
    height:54px;
    display:grid;
    place-items:center;
    margin-bottom:23px;
    border-radius:15px;
    color:var(--primary);
    background:#eff6ff;
}
.feature-icon svg{width:27px;height:27px}
.feature-card h3{
    margin:0 0 11px;
    font-size:20px;
}
.feature-card p{
    margin:0;
    color:var(--muted);
    line-height:1.72;
}
.steps-section{
    background:#fff;
    border-top:1px solid var(--line);
    border-bottom:1px solid var(--line);
}
.steps{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:20px;
}
.step{
    padding:27px;
    border:1px solid var(--line);
    border-radius:18px;
    background:#fff;
}
.step-no{
    width:40px;
    height:40px;
    display:grid;
    place-items:center;
    margin-bottom:21px;
    border-radius:12px;
    color:#fff;
    background:var(--navy);
    font-weight:900;
}
.step h3{margin:0 0 10px}
.step p{
    margin:0;
    color:var(--muted);
    line-height:1.75;
}
.cta{
    padding:75px 0;
}
.cta-box{
    position:relative;
    overflow:hidden;
    padding:46px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:28px;
    border-radius:24px;
    color:#fff;
    background:linear-gradient(135deg,#1d4ed8,#4f46e5);
    box-shadow:0 23px 60px rgba(37,99,235,.26);
}
.cta-box:after{
    content:"";
    position:absolute;
    width:300px;
    height:300px;
    right:-80px;
    top:-150px;
    border-radius:50%;
    background:rgba(255,255,255,.11);
}
.cta-content{position:relative;z-index:2}
.cta h2{
    margin:0 0 10px;
    font-size:31px;
}
.cta p{
    margin:0;
    color:#dbeafe;
}
.cta-button{
    position:relative;
    z-index:2;
    padding:14px 22px;
    border-radius:11px;
    color:#1d4ed8;
    background:#fff;
    font-weight:900;
    white-space:nowrap;
}
.footer{
    padding:25px 0;
    border-top:1px solid var(--line);
    background:#fff;
}
.footer-inner{
    display:flex;
    justify-content:space-between;
    gap:20px;
    color:#667085;
    font-size:14px;
}
.mobile-menu-btn{display:none}
@media(max-width:980px){
    .hero-inner{grid-template-columns:1fr;gap:40px}
    .preview-card{max-width:650px}
    .feature-grid,.steps{grid-template-columns:1fr 1fr}
}
@media(max-width:720px){
    .site-header{position:absolute}
    .header-inner{min-height:70px}
    .main-nav a:not(.login-btn):not(.register-btn){display:none}
    .hero{padding-top:130px;min-height:auto}
    .hero h1{letter-spacing:-1.7px}
    .feature-grid,.steps{grid-template-columns:1fr}
    .cta-box{
        padding:32px;
        align-items:flex-start;
        flex-direction:column;
    }
    .footer-inner{flex-direction:column}
}
@media(max-width:520px){
    .container{width:min(100% - 22px,1200px)}
    .logo{font-size:17px}
    .logo-mark{width:38px;height:38px}
    .main-nav{gap:5px}
    .main-nav a{padding:8px 10px;font-size:13px}
    .hero{padding-bottom:75px}
    .hero-actions a{width:100%}
    .preview-grid{grid-template-columns:1fr}
    .section{padding:62px 0}
}
</style>
</head>
<body>

<header class="site-header">
    <div class="container header-inner">
        <a class="logo" href="<?= e(homeUrl('index.php')) ?>">
            <span class="logo-mark">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                        d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"
                        stroke="currentColor"
                        stroke-width="1.8"
                    />
                    <path
                        d="m4.5 7.8 7.5 4.1 7.5-4.1M12 12v8.2"
                        stroke="currentColor"
                        stroke-width="1.8"
                    />
                </svg>
            </span>
            <span><?= e($siteName) ?></span>
        </a>

        <nav class="main-nav">
            <a href="#features">平台优势</a>
            <a href="#steps">使用流程</a>

            <?php if ($user): ?>
                <a href="<?= e($queryUrl) ?>">查询</a>
                <a href="<?= e($profileUrl) ?>">个人中心</a>
                <a href="<?= e($rechargeUrl) ?>">充值</a>
                <a class="login-btn" href="<?= e($logoutUrl) ?>">退出</a>
            <?php else: ?>
                <a class="login-btn" href="<?= e($loginUrl) ?>">登录</a>
                <a class="register-btn" href="<?= e($registerUrl) ?>">注册</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<section class="hero">
    <div class="container hero-inner">
        <div>
            <div class="hero-badge">
                <i></i>
                专业、快捷、安全的物流信息服务平台
            </div>

            <h1>
                快速筛选物流数据<br>
                <span>高效获取所需信息</span>
            </h1>

            <p class="hero-desc">
                根据国家、城市、州和妥投时间进行精准筛选。
                注册登录后即可充值余额、购买完整单号，
                已购买的数据会永久保存在个人中心。
            </p>

            <div class="hero-actions">
                <a class="primary-action" href="<?= e($queryUrl) ?>">
                    立即开始查询
                </a>

                <?php if ($user): ?>
                    <a class="secondary-action" href="<?= e($profileUrl) ?>">
                        查看我的单号
                    </a>
                <?php else: ?>
                    <a class="secondary-action" href="<?= e($registerUrl) ?>">
                        免费注册账号
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="preview-card">
            <div class="preview-head">
                <span class="preview-title">物流数据预览</span>
                <span class="status-badge">可购买</span>
            </div>

            <div class="track-panel">
                <div class="track-number">1ZY3 ********** 8888</div>

                <div class="route">
                    <span></span>
                    <div class="route-line"></div>
                    <span></span>
                </div>

                <div class="preview-grid">
                    <div class="preview-item">
                        <strong>EUGENE, OR</strong>
                        <small>目的地城市</small>
                    </div>

                    <div class="preview-item">
                        <strong>US</strong>
                        <small>国家</small>
                    </div>

                    <div class="preview-item">
                        <strong>Ground</strong>
                        <small>物流服务类型</small>
                    </div>

                    <div class="preview-item">
                        <strong>安全隐藏</strong>
                        <small>购买后显示完整单号</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="features" class="section">
    <div class="container">
        <div class="section-head">
            <div class="kicker">Platform Features</div>
            <h2>简单高效的物流查询体验</h2>
            <p>
                从查询筛选、余额充值到购买记录管理，
                所有功能都在同一平台内完成。
            </p>
        </div>

        <div class="feature-grid">
            <article class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>
                <h3>精准条件筛选</h3>
                <p>
                    支持按国家、城市、州和时间范围筛选，
                    快速找到符合要求的物流记录。
                </p>
            </article>

            <article class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 7.5h16v11H4v-11Zm0 3h16M16 15h1"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>
                <h3>多种充值方式</h3>
                <p>
                    支持充值卡密和支付宝当面付，
                    余额到账后即可购买需要的完整信息。
                </p>
            </article>

            <article class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M12 3 5 6v5c0 4.7 2.8 8.2 7 10 4.2-1.8 7-5.3 7-10V6l-7-3Zm-3 9 2 2 4-4"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>
                <h3>数据安全保护</h3>
                <p>
                    未购买前自动隐藏完整单号，
                    购买成功后只在购买者个人中心展示。
                </p>
            </article>
        </div>
    </div>
</section>

<section id="steps" class="section steps-section">
    <div class="container">
        <div class="section-head">
            <div class="kicker">How It Works</div>
            <h2>三步完成查询与购买</h2>
            <p>操作简单，注册后即可快速开始使用。</p>
        </div>

        <div class="steps">
            <article class="step">
                <div class="step-no">1</div>
                <h3>注册并登录</h3>
                <p>
                    使用用户名和邮箱创建账号，
                    登录后即可进入查询和充值功能。
                </p>
            </article>

            <article class="step">
                <div class="step-no">2</div>
                <h3>筛选需要的数据</h3>
                <p>
                    输入国家、城市、州和时间条件，
                    从可购买数据中快速完成筛选。
                </p>
            </article>

            <article class="step">
                <div class="step-no">3</div>
                <h3>余额购买查看</h3>
                <p>
                    使用账户余额购买后，
                    完整单号会保存在个人中心供长期查看。
                </p>
            </article>
        </div>
    </div>
</section>

<section class="cta">
    <div class="container">
        <div class="cta-box">
            <div class="cta-content">
                <h2>
                    <?= $user ? '现在开始查找需要的数据' : '立即注册并开始使用' ?>
                </h2>
                <p>
                    <?= $user
                        ? '进入查询页面，按条件筛选当前可购买的物流信息。'
                        : '创建账号后即可充值余额并购买完整单号。'
                    ?>
                </p>
            </div>

            <a
                class="cta-button"
                href="<?= e($user ? $queryUrl : $registerUrl) ?>"
            >
                <?= $user ? '进入查询页面' : '免费注册' ?>
            </a>
        </div>
    </div>
</section>

<footer class="footer">
    <div class="container footer-inner">
        <span>© <?= date('Y') ?> <?= e($siteName) ?></span>
        <span>专业物流信息查询与会员服务平台</span>
    </div>
</footer>

</body>
</html>
