<?php
require __DIR__ . '/lib.php';
installTables();

$user = currentUser();
$price = max(0.01, (float)setting('tracking_price', '1.00'));

$country = clean($_GET['country'] ?? '', 20);
$city = clean($_GET['city'] ?? '', 100);
$state = clean($_GET['state'] ?? '', 50);

/*
 * 发货时间在数据库中是文本，例如：06/21/2026
 * 所以使用手动输入 + LIKE 模糊查询。
 */
$shippedDate = clean($_GET['shipped_date'] ?? '', 50);

/*
 * 妥投时间保持原来的数据库时间格式查询。
 * 数据库示例：2026-06-24 21:00
 */
$startTime = clean(
    str_replace('T', ' ', $_GET['start_time'] ?? ''),
    30
);
$endTime = clean(
    str_replace('T', ' ', $_GET['end_time'] ?? ''),
    30
);

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 50;

/*
 * 清空筛选时返回当前页面，不再固定跳到 query.php。
 * 无论文件名是 index.php、query.php 或其他名称，都不会产生 404。
 */
$currentPagePath = parse_url(
    $_SERVER['REQUEST_URI'] ?? '',
    PHP_URL_PATH
);

if (!is_string($currentPagePath) || $currentPagePath === '') {
    $currentPagePath = $_SERVER['SCRIPT_NAME'] ?? '/';
}

/*
 * 已被任意用户购买的单号直接从前台查询结果中排除。
 */
$where = [
    'NOT EXISTS (
        SELECT 1
        FROM purchase_records sold
        WHERE sold.tracking_id = t.id
    )'
];
$params = [];

if ($country !== '') {
    $where[] = 't.country LIKE :country';
    $params[':country'] = '%' . $country . '%';
}

if ($city !== '') {
    $where[] = 't.city LIKE :city';
    $params[':city'] = '%' . $city . '%';
}

if ($state !== '') {
    $where[] = 't.state LIKE :state';
    $params[':state'] = '%' . $state . '%';
}

/*
 * 发货时间按文本查询。
 * 输入 06/21/2026 可匹配该日期。
 */
if ($shippedDate !== '') {
    $where[] = 't.shipped_date LIKE :shipped_date';
    $params[':shipped_date'] = '%' . $shippedDate . '%';
}

/*
 * 妥投时间继续按开始和结束范围查询。
 */
if ($startTime !== '') {
    $where[] = 't.delivered_time >= :start_time';
    $params[':start_time'] = $startTime;
}

if ($endTime !== '') {
    $where[] = 't.delivered_time <= :end_time';
    $params[':end_time'] = $endTime;
}

$whereSql = ' WHERE ' . implode(' AND ', $where);

$countSql = 'SELECT COUNT(*) FROM tracking_info t' . $whereSql;
$stmt = db()->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$pages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $pages);
$offset = ($page - 1) * $pageSize;

$sql = "
    SELECT t.*
    FROM tracking_info t
    {$whereSql}
    ORDER BY t.delivered_time DESC, t.id DESC
    LIMIT {$pageSize} OFFSET {$offset}
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function pageUrl(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return '?' . http_build_query($query);
}

require __DIR__ . '/header.php';
?>

<div class="card">
    <h1>UPS 物流查询</h1>

    <p class="muted">
        可免费查看国家、城市、州、服务与时间；
        完整单号需要登录并购买。
        每条价格：
        <span class="price">¥<?= h(money($price)) ?></span>。
        已购买的单号会自动从前台下架。
    </p>

    <form method="get" action="">
        <div class="grid">
            <div>
                <label>国家</label>
                <input
                    type="text"
                    name="country"
                    value="<?= h($country) ?>"
                    placeholder="例如：US"
                >
            </div>

            <div>
                <label>城市</label>
                <input
                    type="text"
                    name="city"
                    value="<?= h($city) ?>"
                    placeholder="例如：EUGENE"
                >
            </div>

            <div>
                <label>州</label>
                <input
                    type="text"
                    name="state"
                    value="<?= h($state) ?>"
                    placeholder="例如：OR"
                >
            </div>

            <div>
                <label>发货时间</label>
                <input
                    type="text"
                    name="shipped_date"
                    value="<?= h($shippedDate) ?>"
                    placeholder="例如：06/21/2026"
                    autocomplete="off"
                >
            </div>



            <div>
                <label>妥投结束时间</label>
                <input
                    type="datetime-local"
                    name="end_time"
                    value="<?= h(
                        str_replace(
                            ' ',
                            'T',
                            substr($endTime, 0, 16)
                        )
                    ) ?>"
                >
            </div>
        </div>

        <p class="actions">
            <button type="submit">查询</button>

            <a
                class="btn light"
                href="<?= h($currentPagePath) ?>"
            >
                清空
            </a>
        </p>
    </form>
</div>

<div class="card">
    <div class="actions" style="justify-content:space-between">
        <h2 style="margin:0">可购买结果</h2>
        <span>共 <?= number_format($total) ?> 条</span>
    </div>

    <div class="table">
        <table>
            <thead>
            <tr>
                <th>UPS单号</th>
                <th>国家</th>
                <th>城市</th>
                <th>州</th>
                <th>服务</th>
                <th>发货时间</th>
                <th>妥投时间</th>
                <th>操作</th>
            </tr>
            </thead>

            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td
                        colspan="8"
                        class="muted"
                        style="text-align:center;padding:35px"
                    >
                        没有符合条件的可购买数据
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <span class="mask">
                            <?= h(maskTracking($row['tracking_number'])) ?>
                        </span>
                    </td>

                    <td><?= h($row['country']) ?></td>
                    <td><?= h($row['city']) ?></td>
                    <td><?= h($row['state']) ?></td>
                    <td><?= h($row['service']) ?></td>
                    <td><?= h($row['shipped_date']) ?></td>
                    <td><?= h($row['delivered_time']) ?></td>

                    <td>
                        <?php if (!$user): ?>
                            <a
                                class="btn"
                                href="<?= h(basePath('login.php')) ?>"
                            >
                                登录购买
                            </a>
                        <?php else: ?>
                            <form
                                method="post"
                                action="<?= h(basePath('purchase.php')) ?>"
                                onsubmit="return confirm(
                                    '确认支付 ¥<?= h(money($price)) ?> 购买该单号？'
                                )"
                            >
                                <?= csrfField() ?>

                                <input
                                    type="hidden"
                                    name="tracking_id"
                                    value="<?= (int)$row['id'] ?>"
                                >

                                <button type="submit">
                                    ¥<?= h(money($price)) ?> 购买
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= h(pageUrl($page - 1)) ?>">
                    上一页
                </a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 3);
            $endPage = min($pages, $page + 3);
            ?>

            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="on"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= h(pageUrl($p)) ?>">
                        <?= $p ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
                <a href="<?= h(pageUrl($page + 1)) ?>">
                    下一页
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
