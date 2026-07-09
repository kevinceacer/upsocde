#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
UPS单号批量查询脚本 - 浏览器版本

使用 Playwright 真实浏览器查询，更稳定，不容易被封
"""

import re
import time
import random
import asyncio
from datetime import datetime
from pathlib import Path

from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeoutError
import requests

try:
    import pymysql
except ImportError:
    pymysql = None

# ==================== 配置 ====================

BASE_TRACKING_NUMBER = "1Z0R58E80359817576"  # 基础单号
REPLACE_LAST_N_DIGITS = 7  # 替换最后几位数字（1-10）
START_NUMBER = 9819250  # 起始编号
END_NUMBER = 9999882  # 结束编号

UPS_URL_TEMPLATE = "https://www.ups.com/track?track=yes&trackNums={}&loc=en_US&requester=ST/"

# 数据库API配置（通过HTTP连接，不需要开放3306端口）
USE_HTTP_API = True  # 使用HTTP API方式
DB_API_URL = "https://xxx.com/db.php"  # API地址 优先使用！

# 或者直连MySQL（需要开放3306端口）
# USE_HTTP_API = False
DB_CONFIG = {
    'host': '217.77.9.227',
    'port': 3306,
    'user': 'your_username',
    'password': 'your_password',
    'database': 'ups_tracking',
    'charset': 'utf8mb4'
}

# 代理配置（本地代理）
USE_PROXY = True  # 是否使用代理
PROXY_PORT = 10808  # 本地代理端口（根据你的代理软件修改）
PROXY_SERVER = f"127.0.0.1:{PROXY_PORT}"  # 本地代理地址

# 如果需要认证的代理
# PROXY_SERVER = f"username:password@127.0.0.1:{PROXY_PORT}"

# 浏览器配置
HEADLESS = False  # True=无头模式，False=显示浏览器窗口（调试时建议False）
REQUEST_DELAY = 2  # 每次请求间隔（秒）
PAGE_LOAD_TIMEOUT = 60000  # 页面导航超时（毫秒）
RESULT_WAIT_TIMEOUT = 45000  # 等待UPS结果区域出现（毫秒）
MAX_RETRIES = 1  # 失败重试次数
SAVE_SCREENSHOT = False  # 是否保存截图（调试用）

# 10并发配置：Playwright异步运行10个独立标签页，每个标签页相当于一个查询线程
CONCURRENT_TABS = 15  # 同时查询数量
WORKER_START_INTERVAL = 0.35  # 各线程启动间隔（秒），避免10个请求完全同时发出
BROWSER_RESTART_EVERY = 200  # 每完成200个查询后关闭整个浏览器并重新打开
BROWSER_RESTART_DELAY = 3  # 关闭浏览器后等待几秒再重新打开


# ==============================================


def parse_delivery_time(text: str) -> str:
    """将英文日期时间转换为标准格式"""
    if not text:
        return None

    try:
        months = {
            'january': '01', 'jan': '01',
            'february': '02', 'feb': '02',
            'march': '03', 'mar': '03',
            'april': '04', 'apr': '04',
            'may': '05',
            'june': '06', 'jun': '06',
            'july': '07', 'jul': '07',
            'august': '08', 'aug': '08',
            'september': '09', 'sep': '09',
            'october': '10', 'oct': '10',
            'november': '11', 'nov': '11',
            'december': '12', 'dec': '12',
        }

        date_match = re.search(r'([A-Za-z]+)\s*(\d{1,2})', text)
        if not date_match:
            return text

        month_str = date_match.group(1).lower()
        day = date_match.group(2).zfill(2)

        month = months.get(month_str)
        if not month:
            return text

        time_match = re.search(r'(\d{1,2}):(\d{2})\s*(P\.M\.|A\.M\.|PM|AM)', text, re.I)
        if time_match:
            hour = int(time_match.group(1))
            minute = time_match.group(2)
            period = time_match.group(3).upper()

            if 'P' in period and hour != 12:
                hour += 12
            elif 'A' in period and hour == 12:
                hour = 0

            hour_str = str(hour).zfill(2)
            current_year = datetime.now().year

            return f"{current_year}-{month}-{day} {hour_str}:{minute}"
        else:
            current_year = datetime.now().year
            return f"{current_year}-{month}-{day}"

    except Exception as e:
        return text


def generate_tracking_numbers(base: str, replace_digits: int, start: int, end: int) -> list:
    """生成单号列表"""
    if replace_digits < 1 or replace_digits > 10:
        raise ValueError("replace_digits 必须在 1-10 之间")

    if len(base) < replace_digits:
        raise ValueError(f"基础单号长度({len(base)})小于替换位数({replace_digits})")

    prefix = base[:-replace_digits]

    tracking_numbers = []
    for num in range(start, end + 1):
        suffix = str(num).zfill(replace_digits)
        tracking_number = prefix + suffix
        tracking_numbers.append(tracking_number)

    return tracking_numbers


def init_database():
    """初始化数据库表"""
    if USE_HTTP_API:
        try:
            response = requests.post(DB_API_URL, data={'action': 'init'}, timeout=10)
            result = response.json()
            if result.get('success'):
                print(f"✅ 数据库API连接成功\n")
                return True
            else:
                print(f"❌ 数据库API错误: {result.get('error')}\n")
                return False
        except Exception as e:
            print(f"❌ 数据库API连接失败: {e}\n")
            return False
    else:
        if pymysql is None:
            print("❌ 未安装 pymysql，请执行: pip install pymysql")
            return False
        try:
            conn = pymysql.connect(**DB_CONFIG)
            cursor = conn.cursor()

            cursor.execute("""
                CREATE TABLE IF NOT EXISTS tracking_info (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tracking_number VARCHAR(50) UNIQUE NOT NULL,
                    country VARCHAR(10),
                    city VARCHAR(100),
                    state VARCHAR(50),
                    service VARCHAR(100),
                    shipped_date VARCHAR(50),
                    delivered_time VARCHAR(100),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tracking (tracking_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """)

            conn.commit()
            conn.close()
            print(f"✅ 远程数据库连接成功\n")
            return True

        except Exception as e:
            print(f"❌ 数据库连接失败: {e}\n")
            return False



def load_existing_tracking_numbers() -> set[str]:
    """
    读取数据库中已经存在的UPS单号。

    只使用 tracking_number 判断重复；
    国家、城市、州、服务类型、发货时间、妥投时间相同都不算重复。
    """
    if USE_HTTP_API:
        try:
            response = requests.post(
                DB_API_URL,
                data={'action': 'export'},
                timeout=30
            )
            response.raise_for_status()
            result = response.json()

            if not result.get('success'):
                print(f"⚠️ 无法读取数据库现有单号: {result.get('error')}")
                return set()

            return {
                str(row.get('tracking_number', '')).strip()
                for row in result.get('data', [])
                if str(row.get('tracking_number', '')).strip()
            }

        except Exception as e:
            print(f"⚠️ 无法读取数据库现有单号: {e}")
            return set()

    if pymysql is None:
        return set()

    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor()
        cursor.execute("SELECT tracking_number FROM tracking_info")
        rows = cursor.fetchall()
        conn.close()

        return {
            str(row[0]).strip()
            for row in rows
            if row and str(row[0]).strip()
        }

    except Exception as e:
        print(f"⚠️ 无法读取数据库现有单号: {e}")
        return set()


def save_to_database(info: dict) -> str:
    """
    只新增未存在的单号，不更新重复单号。

    返回：
    - inserted：成功新增
    - duplicate：tracking_number 已存在，未写入、未更新
    - failed：写入失败
    """
    if info.get('status') != 'found':
        return 'failed'

    if USE_HTTP_API:
        try:
            response = requests.post(DB_API_URL, data={
                'action': 'save',
                'insert_only': '1',
                'tracking_number': info['tracking_number'],
                'country': info.get('country'),
                'city': info.get('city'),
                'state': info.get('state'),
                'service': info.get('service'),
                'shipped_date': info.get('shipped_date'),
                'delivered_time': info.get('delivered_time'),
            }, timeout=10)
            response.raise_for_status()

            result = response.json()

            if (
                result.get('duplicate') is True
                or result.get('status') == 'duplicate'
                or result.get('code') == 'duplicate'
            ):
                return 'duplicate'

            return 'inserted' if result.get('success', False) else 'failed'

        except Exception as e:
            print(f"❌ 保存失败: {e}")
            return 'failed'

    if pymysql is None:
        print("❌ 保存失败: 未安装 pymysql，请执行: pip install pymysql")
        return 'failed'

    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor()

        affected_rows = cursor.execute("""
            INSERT IGNORE INTO tracking_info
            (tracking_number, country, city, state, service, shipped_date, delivered_time)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """, (
            info['tracking_number'],
            info.get('country'),
            info.get('city'),
            info.get('state'),
            info.get('service'),
            info.get('shipped_date'),
            info.get('delivered_time'),
        ))

        conn.commit()
        conn.close()

        return 'inserted' if affected_rows == 1 else 'duplicate'

    except Exception as e:
        print(f"❌ 保存失败: {e}")
        return 'failed'


async def _get_first_text(page, selectors: list[str]) -> str | None:
    """依次尝试多个选择器，返回第一个非空文本。"""
    for selector in selectors:
        try:
            locator = page.locator(selector).first
            if await locator.count() > 0:
                text = (await locator.inner_text(timeout=3000)).strip()
                if text:
                    return text
        except Exception:
            continue
    return None


async def _save_debug_screenshot(page, tracking_number: str, suffix: str = ""):
    """按需保存调试截图，截图失败不影响主流程。"""
    if not SAVE_SCREENSHOT:
        return

    try:
        safe_suffix = f"_{suffix}" if suffix else ""
        screenshot_path = Path(__file__).parent / f"debug_{tracking_number}{safe_suffix}.png"
        await page.screenshot(path=str(screenshot_path), full_page=True)
    except Exception:
        pass


async def query_ups_with_browser(page, tracking_number: str) -> dict:
    """使用Playwright查询UPS单号。"""
    url = UPS_URL_TEMPLATE.format(tracking_number)
    navigation_timed_out = False

    try:
        # UPS页面会持续发送统计/接口请求，networkidle 很容易永远等不到。
        # 因此这里只等 DOMContentLoaded，再单独等待结果文字或结果元素。
        try:
            await page.goto(
                url,
                wait_until='domcontentloaded',
                timeout=PAGE_LOAD_TIMEOUT
            )
        except PlaywrightTimeoutError:
            # 导航超时不代表页面没有加载成功；截图中的情况就是页面已显示，
            # 但 networkidle/导航等待仍然超时。所以继续检查当前DOM。
            navigation_timed_out = True

        # 等待UPS前端把结果渲染出来。使用正文关键词而不是限定 h2，
        # 因为UPS会调整标题标签层级。
        try:
            await page.wait_for_function(
                """
                () => {
                    const bodyText = (document.body && document.body.innerText) || '';
                    return bodyText.includes('Tracking Details') ||
                           bodyText.includes('Shipment Details') ||
                           /Invalid tracking number/i.test(bodyText) ||
                           /may be invalid or not active yet/i.test(bodyText) ||
                           /We cannot locate the shipment details/i.test(bodyText) ||
                           /Access Denied|Service Unavailable|Verify you are human/i.test(bodyText);
                }
                """,
                timeout=RESULT_WAIT_TIMEOUT
            )
        except PlaywrightTimeoutError:
            pass

        # 给动态字段一点最终渲染时间。
        await asyncio.sleep(1.5)

        try:
            body_text = await page.locator('body').inner_text(timeout=5000)
        except Exception:
            body_text = ''

        body_text_lower = body_text.lower()

        # 识别UPS反爬/服务错误，交给重试机制处理。
        block_keywords = (
            'access denied',
            'verify you are human',
            'service unavailable',
            'temporarily unavailable',
            'captcha',
        )
        if any(keyword in body_text_lower for keyword in block_keywords):
            await _save_debug_screenshot(page, tracking_number, 'blocked')
            return {
                'tracking_number': tracking_number,
                'status': 'error',
                'error': 'UPS页面被拦截或暂时不可用，请更换代理/IP后重试'
            }

        # 无效或尚未激活。
        invalid_patterns = (
            'invalid tracking number',
            'may be invalid or not active yet',
            'we cannot locate the shipment details',
            'check your tracking number and try again',
        )
        if any(pattern in body_text_lower for pattern in invalid_patterns):
            return {
                'tracking_number': tracking_number,
                'status': 'invalid',
                'error': '无效单号或未激活'
            }

        # 不再强制标题必须是h2，只检查页面正文和关键字段。
        has_tracking_details = 'tracking details' in body_text_lower
        has_shipment_details = 'shipment details' in body_text_lower
        has_result_field = await page.locator(
            '#stApp_txtAddress, '
            '#stApp_txtCountry, '
            '#stApp_link_AdditionalInfoService, '
            '#stApp_txtAdditionalInfoBilledOn'
        ).count() > 0

        if not ((has_tracking_details and has_shipment_details) or has_result_field):
            await _save_debug_screenshot(page, tracking_number, 'not_found')
            timeout_note = '（页面导航曾超时）' if navigation_timed_out else ''
            return {
                'tracking_number': tracking_number,
                'status': 'not_found',
                'error': f'未找到完整物流信息{timeout_note}'
            }

        info = {
            'tracking_number': tracking_number,
            'status': 'found',
            'country': None,
            'city': None,
            'state': None,
            'service': None,
            'shipped_date': None,
            'delivered_time': None,
        }

        # Ship To：优先读UPS固定ID，ID变化时从Shipment Details正文回退提取。
        address_text = await _get_first_text(page, [
            '#stApp_txtAddress',
            '[data-testid="ship-to-address"]',
        ])

        if not address_text:
            match = re.search(
                r'Ship\s*To\s*\n+([^\n]+)',
                body_text,
                re.I
            )
            if match:
                address_text = match.group(1).strip()

        country_text = await _get_first_text(page, [
            '#stApp_txtCountry',
            '[data-testid="ship-to-country"]',
        ])

        if address_text:
            address_text = re.sub(r'\s+', ' ', address_text).strip()
            # 例如：EUGENE, OR US
            match = re.match(r'^(.*?),\s*([A-Za-z]{2})(?:\s+([A-Za-z]{2}))?$', address_text)
            if match:
                info['city'] = match.group(1).strip()
                info['state'] = match.group(2).upper()
                if not country_text and match.group(3):
                    country_text = match.group(3).upper()
            elif ',' in address_text:
                parts = [part.strip() for part in address_text.split(',')]
                info['city'] = parts[0] or None
                info['state'] = parts[1] if len(parts) > 1 else None
            else:
                info['city'] = address_text

        if country_text:
            info['country'] = re.sub(r'\s+', ' ', country_text).strip()

        # 服务类型。
        info['service'] = await _get_first_text(page, [
            '#stApp_link_AdditionalInfoService',
            '#stApp_txtAdditionalInfoService',
            '[data-testid="service"]',
        ])
        if not info['service']:
            match = re.search(
                r'Service\s*\n+([^\n]+)',
                body_text,
                re.I
            )
            if match:
                info['service'] = match.group(1).strip()

        # Shipped / Billed On。
        info['shipped_date'] = await _get_first_text(page, [
            '#stApp_txtAdditionalInfoBilledOn',
            '[data-testid="shipped-billed-on"]',
        ])
        if not info['shipped_date']:
            match = re.search(
                r'Shipped\s*/\s*Billed\s*On\s*\n+([^\n]+)',
                body_text,
                re.I
            )
            if match:
                info['shipped_date'] = match.group(1).strip()

        # 预计送达/妥投时间。优先从“Estimated delivery”后面提取，
        # 可正确处理截图中的：Tomorrow, June 24 by 9:00 P.M.
        delivery_text = None
        match = re.search(
            r'Estimated\s+delivery\s*\n+([^\n]+)',
            body_text,
            re.I
        )
        if match:
            delivery_text = match.group(1).strip()

        # 某些版本会把日期和时间分成多个span。
        if not delivery_text:
            try:
                date_spans = await page.locator('span.d-inline-block.text-nowrap').all()
                span_texts = []
                for span in date_spans:
                    text = (await span.inner_text()).strip()
                    if text:
                        span_texts.append(text)
                if span_texts:
                    delivery_text = ' '.join(span_texts)
            except Exception:
                pass

        if delivery_text:
            info['delivered_time'] = parse_delivery_time(delivery_text)

        return info

    except Exception as e:
        await _save_debug_screenshot(page, tracking_number, 'error')
        return {
            'tracking_number': tracking_number,
            'status': 'error',
            'error': f'{type(e).__name__}: {e}'
        }


async def tracking_worker(
    worker_id: int,
    queue: asyncio.Queue,
    context,
    state: dict,
    state_lock: asyncio.Lock,
    database_lock: asyncio.Lock,
    known_tracking_numbers: set[str],
    total: int,
):
    """一个查询线程：固定复用自己的标签页，循环领取单号。"""
    # 让10个线程错开一点启动，减少UPS瞬间拦截概率。
    await asyncio.sleep((worker_id - 1) * WORKER_START_INTERVAL)
    page = await context.new_page()

    try:
        while True:
            item = await queue.get()

            # None 是线程结束标记。
            if item is None:
                queue.task_done()
                break

            input_index, tracking_number = item

            try:
                print(
                    f"[{input_index}/{total}] [线程{worker_id:02d}] "
                    f"查询单号: {tracking_number}"
                )

                info = None

                for attempt in range(1, MAX_RETRIES + 1):
                    # 页面意外关闭或崩溃时，只重建当前线程自己的标签页。
                    if page.is_closed():
                        page = await context.new_page()

                    info = await query_ups_with_browser(page, tracking_number)

                    if info['status'] in ('found', 'invalid'):
                        break

                    if attempt < MAX_RETRIES:
                        wait_time = attempt * 3
                        print(
                            f"   [线程{worker_id:02d}] ⏳ "
                            f"{info.get('error', '查询失败')}，"
                            f"{wait_time}秒后重试... ({attempt}/{MAX_RETRIES})"
                        )
                        await asyncio.sleep(wait_time)

                        # 清空当前标签页，避免上一次SPA状态干扰重试。
                        try:
                            await page.goto(
                                'about:blank',
                                wait_until='commit',
                                timeout=5000
                            )
                        except Exception:
                            try:
                                await page.close()
                            except Exception:
                                pass
                            page = await context.new_page()
                    else:
                        break

                # 防止极端情况下info仍为空。
                if info is None:
                    info = {
                        'tracking_number': tracking_number,
                        'status': 'error',
                        'error': '查询没有返回结果'
                    }

                if info['status'] == 'found':
                    tracking_number_key = str(info['tracking_number']).strip()

                    async with database_lock:
                        is_duplicate = tracking_number_key in known_tracking_numbers
                        if not is_duplicate:
                            known_tracking_numbers.add(tracking_number_key)

                    if is_duplicate:
                        save_result = 'duplicate'
                    else:
                        save_result = await asyncio.to_thread(
                            save_to_database,
                            info
                        )

                        if save_result == 'failed':
                            async with database_lock:
                                known_tracking_numbers.discard(
                                    tracking_number_key
                                )

                    async with state_lock:
                        state['success'] += 1

                        if save_result == 'inserted':
                            state['saved'] += 1
                        elif save_result == 'duplicate':
                            state['duplicates'] += 1
                        else:
                            state['save_failed'] += 1

                    location = (
                        f"{info.get('city')}, {info.get('state')} "
                        f"{info.get('country')}"
                    ).strip()

                    if save_result == 'inserted':
                        print(
                            f"   [线程{worker_id:02d}] ✅ 找到并新增到数据库: "
                            f"{location} | {info.get('service')}"
                        )
                    elif save_result == 'duplicate':
                        print(
                            f"   [线程{worker_id:02d}] ⏭️ 单号已存在，"
                            f"不写入、不更新: {tracking_number_key}"
                        )
                    else:
                        print(
                            f"   [线程{worker_id:02d}] ⚠️ 找到物流信息，"
                            f"但数据库写入失败: {location} | {info.get('service')}"
                        )

                elif info['status'] == 'invalid':
                    async with state_lock:
                        state['not_found'] += 1
                    print(f"   [线程{worker_id:02d}] ⚠️ 无效单号（跳过）")

                elif info['status'] == 'not_found':
                    async with state_lock:
                        state['not_found'] += 1
                    print(f"   [线程{worker_id:02d}] ⚠️ 未找到物流信息")

                else:
                    async with state_lock:
                        state['errors'] += 1
                    print(
                        f"   [线程{worker_id:02d}] ❌ 错误: "
                        f"{info.get('error')}"
                    )

                async with state_lock:
                    state['completed'] += 1
                    completed = state['completed']
                    success = state['success']
                    not_found = state['not_found']
                    errors = state['errors']
                    saved_count = state['saved']
                    duplicate_count = state['duplicates']

                print(
                    f"   📊 总进度 {completed}/{total} | "
                    f"找到 {success} | 新增入库 {saved_count} | "
                    f"重复跳过 {duplicate_count} | "
                    f"无效/未找到 {not_found} | 错误 {errors}"
                )

                # 每个线程查询完一条后稍作停顿。
                if REQUEST_DELAY > 0 and completed < total:
                    await asyncio.sleep(REQUEST_DELAY)

            except Exception as e:
                async with state_lock:
                    state['errors'] += 1
                    state['completed'] += 1
                print(
                    f"   [线程{worker_id:02d}] ❌ 线程处理异常: "
                    f"{type(e).__name__}: {e}"
                )
            finally:
                queue.task_done()

    finally:
        try:
            if not page.is_closed():
                await page.close()
        except Exception:
            pass


async def launch_fresh_browser(playwright):
    """启动一个全新的浏览器和上下文。"""
    launch_options = {
        'headless': HEADLESS
    }

    if USE_PROXY:
        launch_options['proxy'] = {
            'server': f'http://{PROXY_SERVER}'
        }

    try:
        browser = await playwright.chromium.launch(
            channel='chrome',
            **launch_options
        )
        browser_name = "系统Chrome"
    except Exception:
        browser = await playwright.chromium.launch(**launch_options)
        browser_name = "Playwright Chromium"

    context = await browser.new_context(
        viewport={'width': 1920, 'height': 1080},
        locale='en-US',
        ignore_https_errors=True,
        service_workers='block'
    )
    context.set_default_timeout(15000)
    context.set_default_navigation_timeout(PAGE_LOAD_TIMEOUT)

    return browser, context, browser_name


async def run_query_batch(
    playwright,
    batch_items: list[tuple[int, str]],
    batch_number: int,
    total_batches: int,
    state: dict,
    state_lock: asyncio.Lock,
    database_lock: asyncio.Lock,
    known_tracking_numbers: set[str],
    total: int,
):
    """
    运行一批查询。

    每批最多 BROWSER_RESTART_EVERY 个单号。
    本批结束后会关闭整个浏览器，下一批重新启动新浏览器。
    """
    batch_size = len(batch_items)
    worker_count = min(CONCURRENT_TABS, batch_size)

    print()
    print("=" * 60)
    print(
        f"🌐 启动第 {batch_number}/{total_batches} 批浏览器 "
        f"| 本批 {batch_size} 个 | 并发 {worker_count}"
    )
    print("=" * 60)

    browser = None
    context = None
    workers = []
    queue = asyncio.Queue()

    try:
        browser, context, browser_name = await launch_fresh_browser(playwright)
        print(f"✅ 已打开全新{browser_name}")

        for item in batch_items:
            await queue.put(item)

        # 每个工作线程放入一个结束标记。
        for _ in range(worker_count):
            await queue.put(None)

        workers = [
            asyncio.create_task(
                tracking_worker(
                    worker_id=i,
                    queue=queue,
                    context=context,
                    state=state,
                    state_lock=state_lock,
                    database_lock=database_lock,
                    known_tracking_numbers=known_tracking_numbers,
                    total=total,
                )
            )
            for i in range(1, worker_count + 1)
        ]

        await queue.join()
        await asyncio.gather(*workers)

    finally:
        for task in workers:
            if not task.done():
                task.cancel()

        if workers:
            await asyncio.gather(*workers, return_exceptions=True)

        if context is not None:
            try:
                await context.close()
            except Exception:
                pass

        if browser is not None:
            try:
                await browser.close()
            except Exception:
                pass

        print(
            f"🛑 第 {batch_number}/{total_batches} 批结束，"
            f"浏览器已完全关闭"
        )


async def main():
    print("=" * 60)
    print("UPS 单号批量查询脚本 - 浏览器定期重启版")
    print("=" * 60)
    print()

    # 初始化数据库放进后台线程，避免阻塞异步主循环。
    database_ready = await asyncio.to_thread(init_database)
    if not database_ready:
        return

    known_tracking_numbers = await asyncio.to_thread(
        load_existing_tracking_numbers
    )
    print(
        f"📚 数据库已有 {len(known_tracking_numbers)} 个不重复单号，"
        f"查询命中后将自动跳过重复单号\n"
    )

    print("📝 配置信息:")
    print(f"   基础单号: {BASE_TRACKING_NUMBER}")
    print(f"   替换后 {REPLACE_LAST_N_DIGITS} 位数字")
    print(f"   查询范围: {START_NUMBER} - {END_NUMBER}")
    print(f"   浏览器模式: {'无头' if HEADLESS else '可见'}")
    print(f"   数据库模式: {'HTTP API' if USE_HTTP_API else '直连MySQL'}")
    print(f"   并发线程数: {CONCURRENT_TABS}")
    print(f"   每查询 {BROWSER_RESTART_EVERY} 个后重启浏览器")
    print()

    tracking_numbers = generate_tracking_numbers(
        BASE_TRACKING_NUMBER,
        REPLACE_LAST_N_DIGITS,
        START_NUMBER,
        END_NUMBER
    )

    total = len(tracking_numbers)
    indexed_tracking_numbers = list(enumerate(tracking_numbers, 1))

    batches = [
        indexed_tracking_numbers[i:i + BROWSER_RESTART_EVERY]
        for i in range(0, total, BROWSER_RESTART_EVERY)
    ]
    total_batches = len(batches)

    print(f"✅ 已生成 {total} 个单号")
    print(f"   示例: {tracking_numbers[0]} ... {tracking_numbers[-1]}")
    print(f"📦 共分成 {total_batches} 批，每批最多 {BROWSER_RESTART_EVERY} 个")
    if USE_PROXY:
        print(f"🔒 代理模式: 已启用 ({PROXY_SERVER})")
    print()

    state = {
        'completed': 0,
        'success': 0,
        'saved': 0,
        'duplicates': 0,
        'save_failed': 0,
        'not_found': 0,
        'errors': 0,
    }
    state_lock = asyncio.Lock()
    database_lock = asyncio.Lock()

    async with async_playwright() as playwright:
        for batch_index, batch_items in enumerate(batches, 1):
            try:
                await run_query_batch(
                    playwright=playwright,
                    batch_items=batch_items,
                    batch_number=batch_index,
                    total_batches=total_batches,
                    state=state,
                    state_lock=state_lock,
                    database_lock=database_lock,
                    known_tracking_numbers=known_tracking_numbers,
                    total=total,
                )
            except Exception as e:
                print(
                    f"❌ 第 {batch_index}/{total_batches} 批发生异常: "
                    f"{type(e).__name__}: {e}"
                )
                print("⏳ 5秒后重新启动浏览器继续下一批...")
                await asyncio.sleep(5)

            if batch_index < total_batches:
                print(
                    f"♻️ 将重新打开浏览器继续下一批，"
                    f"{BROWSER_RESTART_DELAY} 秒后启动..."
                )
                await asyncio.sleep(BROWSER_RESTART_DELAY)

    print()
    print("=" * 60)
    print("📊 查询完成！")
    print("=" * 60)
    print(f"总计: {total} 个单号")
    print(f"已处理: {state['completed']} 个")
    print(f"找到: {state['success']} 个 ✅")
    print(f"新增写入数据库: {state['saved']} 个 ✅")
    print(f"单号重复跳过: {state['duplicates']} 个 ⏭️")
    print(f"数据库写入失败: {state['save_failed']} 个 ⚠️")
    print(f"无效/未找到: {state['not_found']} 个 ⚠️")
    print(f"查询错误: {state['errors']} 个 ❌")
    print()

    # 只有真正写入数据库后才导出CSV。
    if state['saved'] > 0:
        await asyncio.to_thread(export_to_csv)

def export_to_csv():
    """导出为CSV文件"""
    import csv

    try:
        if USE_HTTP_API:
            # 通过API导出
            response = requests.post(DB_API_URL, data={'action': 'export'}, timeout=30)
            result = response.json()

            if not result.get('success'):
                print(f"❌ 导出失败: {result.get('error')}")
                return

            rows = result.get('data', [])
            if not rows:
                print("\n⚠️  数据库中没有数据")
                return

            csv_file = Path(__file__).with_name("ups_tracking_export.csv")

            with open(csv_file, 'w', newline='', encoding='utf-8-sig') as f:
                writer = csv.writer(f)
                writer.writerow(['单号', '国家', '城市', '州', '服务类型', '发货时间', '妥投时间'])

                for row in rows:
                    writer.writerow([
                        row['tracking_number'],
                        row['country'],
                        row['city'],
                        row['state'],
                        row['service'],
                        row['shipped_date'],
                        row['delivered_time']
                    ])

            print(f"\n📊 已导出到CSV: {csv_file}")
            print(f"   共 {len(rows)} 条记录")

        else:
            # 直连数据库导出
            if pymysql is None:
                print("❌ 导出失败: 未安装 pymysql，请执行: pip install pymysql")
                return
            conn = pymysql.connect(**DB_CONFIG)
            cursor = conn.cursor()

            cursor.execute("""
                SELECT tracking_number, country, city, state, service, shipped_date, delivered_time
                FROM tracking_info
                ORDER BY tracking_number
            """)

            rows = cursor.fetchall()
            conn.close()

            if not rows:
                print("\n⚠️  数据库中没有数据")
                return

            csv_file = Path(__file__).with_name("ups_tracking_export.csv")

            with open(csv_file, 'w', newline='', encoding='utf-8-sig') as f:
                writer = csv.writer(f)
                writer.writerow(['单号', '国家', '城市', '州', '服务类型', '发货时间', '妥投时间'])
                writer.writerows(rows)

            print(f"\n📊 已导出到CSV: {csv_file}")
            print(f"   共 {len(rows)} 条记录")

    except Exception as e:
        print(f"\n❌ 导出失败: {e}")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\n\n⚠️  用户中断")
    except Exception as e:
        print(f"\n❌ 发生错误: {e}")
        import traceback

        traceback.print_exc()
