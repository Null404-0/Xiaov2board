# 从 v2board-duplication（原版）迁移到 xiaov2board（dedicated-nodes 版）

> **方向**：生产端正在跑 [`null404-0/v2board-duplication`](https://github.com/null404-0/v2board-duplication)（fork 自 V2bX 上游的纯净版），需要切到本仓库 [`null404-0/xiaov2board`](https://github.com/null404-0/xiaov2board)（在原版基础上加了"用户专属节点"功能）。
>
> **场景**：你目前在用大量"专属用途"的 `v2_server_group` + 把用户/节点都塞进这些 group 来模拟"专属节点"，造成权限组管理地狱。本仓库提供 `v2_server_user` 表（直接 (server, user) 配对）来彻底取代这套用法。

## TL;DR

- **代码差异**：本仓库比原版多了 `v2_server_user` 表 + `ServerUser` Model + `UserAssignController` + 后台 `dedicated-nodes` 视图 + `ServerService` 中"专属节点对其他用户隐藏"的可见性逻辑 + UniProxy 调用差异。其它（用户/订单/套餐/服务器表/订阅/支付/UniProxy 协议字段）**完全一致**。
- **数据库改动**：只新增一张表 `v2_server_user`。**`bash update.sh` 自动建表**，无需手动 DDL。
- **数据迁移（这次的大头）**：你现有的"权限组式专属"需要**人工 + 脚本**两步搞定 —— 因为 `v2_server_*.group_id` 是 JSON 数组、纯 SQL 不好操作。我提供 PHP 脚本（dry-run 优先）。
- **关于"原版直接 bash update.sh 就行"**：本仓库**也是直接 bash update.sh 就能完成代码 + 表结构升级**。`v2_server_user` 的 `CREATE TABLE IF NOT EXISTS` 写在 `database/update.sql` 末尾，`php artisan v2board:update` 会执行。**额外** 需要的就是数据迁移脚本（§5）。

---

## 1. 仓库间精确差异

`diff -rq v2board-duplication/ xiaov2board/`（去掉 `.git`）：

```
Only in xiaov2board/app/Http/Controllers/V1/Admin/Server: UserAssignController.php
Only in xiaov2board/app/Models: ServerUser.php
Only in xiaov2board/resources/views: dedicated-nodes.blade.php
Files differ: app/Http/Routes/V1/AdminRoute.php
Files differ: app/Http/Controllers/V1/Server/UniProxyController.php
Files differ: app/Services/ServerService.php
Files differ: routes/web.php
Files differ: database/install.sql
Files differ: database/update.sql
```

| 项 | v2board-duplication（原版） | xiaov2board（魔改版） |
|---|---|---|
| `v2_server_user` 表 | ❌ 无 | ✅ 新增 |
| `ServerUser` Model | ❌ 无 | ✅ 新增 |
| `UserAssignController`（fetch / save / searchUsers） | ❌ 无 | ✅ 新增 |
| 后台路由 `/server/userAssign/*` | ❌ 无 | ✅ 新增 3 条 |
| 后台页面 `/<secure_path>/dedicated-nodes` | ❌ 无 | ✅ 新增 |
| `ServerService` 可见性 | 纯 group_id | 节点被任意用户专属过 → 仅对被分配的用户可见；否则走 group_id |
| `UniProxyController` | `getAvailableUsers($group)` | `getAvailableUsers($group, $serverId, $type)` |

`v2_server_user` 表结构：

```sql
CREATE TABLE `v2_server_user` (
  `server_id`   int(10) unsigned NOT NULL,
  `server_type` varchar(32)      NOT NULL,
  `user_id`     int(10) unsigned NOT NULL,
  PRIMARY KEY (`server_id`, `server_type`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> 含义：每一行 = 「`server_id` 这个 `server_type` 类型的节点专属给 `user_id`」。同一节点可以被多个用户专属。

---

## 2. 魔改版可见性语义（理解后再迁移）

切到本仓库后，节点对用户的可见性规则如下（来自 `app/Services/ServerService.php` 的 `isServerVisible`）：

```
对每个节点 (server_id, server_type)：
  如果在 v2_server_user 里出现过任何记录（即"被任何人专属过"）：
      → 仅对 v2_server_user 中 user_id 命中的用户可见
      → 其它用户（即使同 group）完全看不到（同时仍要满足 show=1、用户流量、未过期、未 banned）
  否则：
      → 走原来的 v2_server_*.group_id 数组 ∋ user.group_id 逻辑
```

**关键含义**：迁移完成后，"普通节点 + group 分发" 这套机制依然有效。`v2_server_user` 只用于"想真正限制少数用户专访"的节点。**你不需要把所有 group 都拆掉**，只要把"专属用途的 group"拆成 `v2_server_user` 行即可。

---

## 3. 迁移前预检查（在生产 DB 跑）

```sql
-- 3.1 当前总览
SELECT
  (SELECT COUNT(*) FROM v2_server_group)              AS group_total,
  (SELECT COUNT(*) FROM v2_user)                      AS user_total,
  (SELECT COUNT(*) FROM v2_plan)                      AS plan_total;

-- 3.2 哪些 group 看起来"像专属用途"
-- 启发式：被 v2_user 引用的人数较少、且不是任何 v2_plan.group_id 的目标
SELECT
  g.id, g.name,
  (SELECT COUNT(*) FROM v2_user WHERE group_id = g.id)  AS user_cnt,
  (SELECT COUNT(*) FROM v2_plan WHERE group_id = g.id)  AS plan_cnt
FROM v2_server_group g
ORDER BY plan_cnt ASC, user_cnt ASC;
```

把 **`plan_cnt = 0`** 的那些 group 重点关注 —— 通常它们就是你为了"专属节点"专门造的、和任何套餐都不绑定的 group。

```sql
-- 3.3 这些"疑似专属 group"对应的用户名单（导出 CSV 备查）
SELECT g.id AS group_id, g.name AS group_name, u.id AS user_id, u.email
FROM v2_server_group g
JOIN v2_user u ON u.group_id = g.id
WHERE g.id NOT IN (SELECT DISTINCT group_id FROM v2_plan)
ORDER BY g.id, u.id;
```

> ⚠️ **现状盘点先于 schema 改动**。先把 §3 的查询结果**导出存档**，这是后续"权限组 → v2_server_user"映射的事实依据。

---

## 4. 完整备份（必做）

```bash
# 数据库一致性快照
mysqldump -u<user> -p<pwd> --single-transaction --routines --triggers \
  <db_name> > /backup/v2b-$(date +%F-%H%M).sql

# 代码 + 上传文件 + .env
tar czf /backup/v2b-code-$(date +%F-%H%M).tgz \
  --exclude='vendor' --exclude='node_modules' /www/wwwroot/v2board

# 当前 commit
cd /www/wwwroot/v2board && git rev-parse HEAD > /backup/v2b-commit-$(date +%F).txt
```

---

## 5. 数据迁移脚本（"权限组式专属" → `v2_server_user`）

### 5.1 思路

输入：你给出的「专属 group → 用户列表 → 节点列表」映射。
输出：往 `v2_server_user` 插入 (server_id, server_type, user_id) 三元组；可选地，把这些 group 从节点的 `group_id` JSON 数组中移除、把这些用户的 `group_id` 改回他们 plan 对应的真实 group。

为什么不用纯 SQL：`v2_server_*.group_id` 是 Laravel cast 的 JSON 数组（如 `["3","7","12"]`），`JSON_CONTAINS` 在某些 MySQL 版本上麻烦、且 JSON 元素经常以字符串形式存（`"7"` 而非 `7`），更适合 PHP 处理。

### 5.2 把脚本放进项目

文件：`app/Console/Commands/MigrateGroupToServerUser.php`（artisan 命令）

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateGroupToServerUser extends Command
{
    protected $signature = 'v2board:migrate-group-to-server-user
                            {--groups= : 逗号分隔的"专属用途" group_id 列表，例如 7,12,31}
                            {--reassign-plan-group : 把命中用户的 group_id 改回其 plan 的 group_id（推荐两阶段执行）}
                            {--strip-from-servers : 把这些 group 从节点的 group_id 数组中移除（推荐两阶段执行）}
                            {--dry-run : 只打印将做什么，不写库}';

    protected $description = '把"为专属节点而建的 group"翻译成 v2_server_user 记录。';

    private array $serverTables = [
        'shadowsocks' => 'v2_server_shadowsocks',
        'vmess'       => 'v2_server_vmess',
        'trojan'      => 'v2_server_trojan',
        'vless'       => 'v2_server_vless',
        'tuic'        => 'v2_server_tuic',
        'hysteria'    => 'v2_server_hysteria',
        'anytls'      => 'v2_server_anytls',
        'v2node'      => 'v2_server_v2node',
    ];

    public function handle(): int
    {
        $groupsRaw = (string)$this->option('groups');
        if ($groupsRaw === '') {
            $this->error('必须传 --groups=逗号分隔的 group_id 列表');
            return 1;
        }
        $targetGroups = array_values(array_filter(array_map('intval', explode(',', $groupsRaw))));
        $dryRun       = (bool)$this->option('dry-run');
        $reassign     = (bool)$this->option('reassign-plan-group');
        $strip        = (bool)$this->option('strip-from-servers');

        $this->info(sprintf('Target groups: [%s]  dry-run=%s', implode(',', $targetGroups), $dryRun ? 'YES' : 'no'));

        $insertBuffer = [];

        foreach ($targetGroups as $gid) {
            $userIds = DB::table('v2_user')->where('group_id', $gid)->pluck('id')->all();
            if (empty($userIds)) {
                $this->warn("  group=$gid : 没有用户，跳过");
                continue;
            }
            $this->info("  group=$gid : 用户 " . count($userIds) . " 个");

            foreach ($this->serverTables as $type => $table) {
                $rows = DB::table($table)->select('id', 'group_id')->get();
                foreach ($rows as $row) {
                    $serverGroups = json_decode($row->group_id, true) ?: [];
                    // server.group_id 中的元素经常是字符串 "7"，做宽松比较
                    $hit = false;
                    foreach ($serverGroups as $sg) {
                        if ((int)$sg === $gid) { $hit = true; break; }
                    }
                    if (!$hit) continue;

                    foreach ($userIds as $uid) {
                        $insertBuffer[] = [
                            'server_id'   => (int)$row->id,
                            'server_type' => $type,
                            'user_id'     => (int)$uid,
                        ];
                    }
                }
            }
        }

        // 去重（同一 user 可能在多个目标 group 命中同一个节点）
        $uniq = [];
        foreach ($insertBuffer as $r) {
            $uniq[$r['server_id'].'|'.$r['server_type'].'|'.$r['user_id']] = $r;
        }
        $insertBuffer = array_values($uniq);

        $this->info("将插入 v2_server_user 行数：" . count($insertBuffer));
        if ($this->getOutput()->isVerbose()) {
            foreach (array_slice($insertBuffer, 0, 20) as $r) {
                $this->line(sprintf("  %-12s id=%d user=%d", $r['server_type'], $r['server_id'], $r['user_id']));
            }
            if (count($insertBuffer) > 20) $this->line(sprintf("  … 还有 %d 行", count($insertBuffer) - 20));
        }

        if ($dryRun) {
            $this->warn('Dry-run，不写库');
        } else {
            DB::transaction(function () use ($insertBuffer, $reassign, $strip, $targetGroups) {
                // 写 v2_server_user（IGNORE 防主键冲突）
                foreach (array_chunk($insertBuffer, 1000) as $chunk) {
                    DB::table('v2_server_user')->insertOrIgnore($chunk);
                }

                if ($reassign) {
                    foreach ($targetGroups as $gid) {
                        // 把这些用户的 group_id 改回其 plan 对应的 group
                        DB::statement('
                            UPDATE v2_user u
                            JOIN v2_plan  p ON p.id = u.plan_id
                            SET u.group_id = p.group_id
                            WHERE u.group_id = ?
                        ', [$gid]);
                        // 没买套餐的用户：group_id 置 NULL
                        DB::table('v2_user')->where('group_id', $gid)->whereNull('plan_id')->update(['group_id' => null]);
                    }
                }

                if ($strip) {
                    foreach ($this->serverTables as $type => $table) {
                        $rows = DB::table($table)->select('id', 'group_id')->get();
                        foreach ($rows as $row) {
                            $arr = json_decode($row->group_id, true) ?: [];
                            $cleaned = array_values(array_filter($arr, fn($x) => !in_array((int)$x, $targetGroups, true)));
                            if (count($cleaned) !== count($arr)) {
                                DB::table($table)->where('id', $row->id)
                                    ->update(['group_id' => json_encode($cleaned)]);
                            }
                        }
                    }
                }
            });
            $this->info('完成');
        }
        return 0;
    }
}
```

> 把这个文件保存到 `app/Console/Commands/` 后会被 Laravel 自动发现。建议先在 PR 里把它合到主线，部署 xiaov2board 时即可使用 `php artisan v2board:migrate-group-to-server-user ...`。

### 5.3 推荐的两阶段执行

**阶段 A（迁后立刻跑）：只插入 `v2_server_user`，不动 group 结构**

```bash
php artisan v2board:migrate-group-to-server-user --groups=7,12,31 --dry-run -v
# 看打印结果对不对，再去掉 --dry-run
php artisan v2board:migrate-group-to-server-user --groups=7,12,31
```

此时：
- `v2_server_user` 已建立专属关系（魔改版可见性逻辑生效，只对被分配用户可见）
- 但用户的 `group_id` 还指着原专属 group、节点的 `group_id` 数组里也还有这个 group
- **效果**：业务行为已经正确（被分配用户能看到、其他人看不到，因为魔改版"被专属过的节点不再走 group 逻辑"）。可以稳定运行 1~2 周观察。

**阶段 B（确认稳定后清理 group）**

```bash
php artisan v2board:migrate-group-to-server-user \
    --groups=7,12,31 \
    --reassign-plan-group --strip-from-servers
# 然后人工删掉这些 group：
mysql> DELETE FROM v2_server_group WHERE id IN (7,12,31);
```

阶段 B 后你的"权限组管理地狱"就消失了。

---

## 6. 切换步骤（建议在维护窗口内）

```bash
cd /www/wwwroot/v2board

# 6.1 停服
php -c cli-php.ini webman.php stop

# 6.2 切代码源到魔改版
git remote set-url origin https://github.com/null404-0/xiaov2board.git
git fetch origin
git checkout master
git reset --hard origin/master

# 6.3 装依赖 + 跑 v2board:update（自带脚本）
bash update.sh
# 等价于：composer update && php artisan v2board:update
# v2board:update 会执行 database/update.sql 里"对当前库还没跑过"的语句，
# 包括 CREATE TABLE IF NOT EXISTS v2_server_user

# 6.4 验证表已建好
mysql> SHOW CREATE TABLE v2_server_user\G

# 6.5 跑数据迁移（阶段 A，只插 v2_server_user）
php artisan v2board:migrate-group-to-server-user --groups=<你筛出来的列表> --dry-run -v
php artisan v2board:migrate-group-to-server-user --groups=<同上>

# 6.6 清缓存 + 启动
php artisan cache:clear && php artisan config:clear && php artisan route:clear
php -c cli-php.ini start.php start -d
```

`.env` / `storage/` / `public/uploads/` 不在 git 跟踪范围，`git reset --hard` 不会动它们。先 `git status -uall` 自查一遍，避免有人在生产改过被跟踪文件。

---

## 7. 切换后验证

```bash
# 进程
ps -ef | grep -E 'webman|workerman'
# 日志
tail -f storage/logs/$(date +%F).log
tail -f storage/logs/laravel.log
# 后台是否有 dedicated-nodes 页面
curl -I https://你的域名/<secure_path>/dedicated-nodes
# UniProxy 拉用户列表（最易出问题的点）
curl 'https://你的域名/api/v1/server/UniProxy/user?token=<节点token>&node_id=<id>&node_type=<type>'
```

业务必跑：
- [ ] 普通用户登录 → 订阅 → 拿到节点（数量符合预期）
- [ ] 「应该只有 X、Y 用户能看到的专属节点」用 X 的订阅看 → 在；用 Z 的订阅看 → **不在**
- [ ] 节点服务端推流量、拉用户都正常
- [ ] 后台进 `/<secure_path>/dedicated-nodes` 页面能看到迁移结果
- [ ] 下单 / 支付 / 工单链路通畅

---

## 8. 回滚

如果发现问题需要回到原版：

```bash
cd /www/wwwroot/v2board
php -c cli-php.ini webman.php stop
git remote set-url origin https://github.com/null404-0/v2board-duplication.git
git fetch origin
git reset --hard <旧 commit>
bash update.sh
php -c cli-php.ini start.php start -d
```

数据库层面：
- **如果只跑了阶段 A**（没用 `--reassign-plan-group --strip-from-servers`）：原版完全不读 `v2_server_user`，回滚后专属节点会回到"原 group 全员可见"的旧行为，业务正常。`v2_server_user` 表保留作为孤儿表。
- **如果跑了阶段 B**：用户的 `group_id` 和节点的 `group_id` 数组已变更，原版回滚后这些专属节点对原本的"专属用户"也看不见了。**必须从 §4 的 mysqldump 还原** `v2_user.group_id` 与 `v2_server_*.group_id` 两组数据。这是为什么强烈建议「阶段 A 跑完观察至少一周再做阶段 B」。

---

## 9. 常见踩坑

- **`bash update.sh` 报错 `Table 'v2_server_user' already exists`**：之前手动建过，把 `database/update.sql` 末尾的 `CREATE TABLE` 改成 `CREATE TABLE IF NOT EXISTS` 即可（本仓库已经是这样写的，所以一般不会报错）。
- **节点服务端拉到的用户列表突然变少**：检查这些用户的节点是否被你列入 `--groups` 但又**没有被加进** `v2_server_user`（比如脚本 dry-run 后忘了执行实际迁移）。`SELECT * FROM v2_server_user WHERE server_type=? AND server_id=?` 自查。
- **JSON 数组里既有 `7`（int） 又有 `"7"`（string）**：脚本用 `(int)$sg === $gid` 做宽松比较，应该都能命中。如果你生产里有奇怪写法，跑前先抽查 `SELECT id, group_id FROM v2_server_vless LIMIT 5;`。
- **后台 `/dedicated-nodes` 页面访问 404**：检查 `secure_path`（即 `v2board.secure_path` / `v2board.frontend_admin_path` / `crc32(app.key)`）是不是用的对路径；并清掉路由缓存。
- **某些"专属用户"是没买套餐的（`plan_id IS NULL`）**：`--reassign-plan-group` 会把他们 `group_id` 置 NULL（即没节点权限），符合"试用过期 / 退订就该看不到节点"的语义。如果你的试用用户没 plan 但仍要给节点，那需要单独处理。

---

## 10. 关于「原版直接 `bash update.sh` 就行」

完全成立 —— 而且本仓库**也是直接 `bash update.sh` 就够了**：

| 项 | 是否 `bash update.sh` 能搞定 |
|---|---|
| 拉新代码 | ✅ |
| `composer update` | ✅ |
| 装 adapterman（PHP 8+） | ✅ |
| `CREATE TABLE v2_server_user` | ✅（在 `database/update.sql` 末尾） |
| **现有"权限组式专属" → `v2_server_user` 数据迁移** | ❌ 这是你这个 case 独有的、需手工跑 §5 的 artisan 命令 |

所以"额外工作"只有一项：**§5 的数据迁移脚本**。代码层面无任何额外操作。

---

## 附：单条命令拉两个仓库做差异自查

```bash
git clone --depth=1 https://github.com/null404-0/v2board-duplication.git orig
git clone --depth=1 https://github.com/null404-0/xiaov2board.git mod
diff -rq orig mod | grep -v '\.git'
```
