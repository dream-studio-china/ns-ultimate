# Runbook：宝塔 + Apache 生产部署

## 目的

本流程用于在宝塔管理的裸机 Linux 服务器上部署生产环境：Apache 2、PHP 8.5-FPM 和 MySQL。管理前端是发布到 `/admin/` 的 Vite 静态构建；Symfony 后端唯一入口为 `integration/backend/public/index.php`。

这不是 Docker 部署流程。不要在生产主机运行开发目标。

## 1. 部署前检查

开始修改服务器前，记录以下配置：

| 配置 | 示例 | 核实内容 |
| --- | --- | --- |
| 域名 | `example.com` | DNS 指向服务器，TLS 证书已准备 |
| 项目根目录 | `/www/wwwroot/example.com/ns-ultimate` | 包含 `Makefile`、`core/`、`integration/`、`business/` |
| PHP-FPM 用户/组 | `www:www` | 查看 PHP 8.5 pool，不要想当然 |
| PHP CLI | PHP 8.5 | `php -v` 和扩展与 FPM 一致 |
| MySQL 版本 | 实际服务端版本 | 用于 `DATABASE_URL` 的 `serverVersion` |
| 密钥存储 | 公开 Web 根目录之外 | PHP-FPM 可读，不可写 |
| 共享数据目录 | 持久化路径 | PHP-FPM 可写，且已配置备份 |

安装/检查 Apache、PHP 8.5-FPM、Composer 2、MySQL，以及 PHP 扩展：`ctype`、`iconv`、`openssl`、`intl`、`mbstring`、`curl`、`fileinfo`、`xml`/`dom`、`zip`、`pdo_mysql`。若在服务器构建前端，安装 Node.js 22；也可在 CI 或可信工作站构建 `dist/admin/` 后上传。

同时核实 SSH 下的 CLI PHP 和 PHP-FPM。宝塔站点选择的 PHP 版本不一定等于 SSH `PATH` 中的 `php`。运行 Composer 和 Symfony 命令前，先选择 PHP 8.5 CLI。

## 2. 部署版本并安装依赖

将完整仓库（包括 Git 子树和锁文件）部署到 release/项目目录。手动或基于 release 的部署应使用非 root 发布账号；下方的简单原地更新脚本则适用于只有 root 权限的宝塔环境：Git、Composer、npm 和静态资源安装由 root 执行，数据库迁移及缓存命令切换为 PHP-FPM 用户。生产密钥、JWT 密钥、上传文件和持久化数据放在公开文档根目录之外；release 模式应优先放到各 release 目录之外的共享路径。

在项目根目录安装生产 Composer 依赖：

```sh
composer install \
  --working-dir=core/crud-skeleton \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts
```

若 CI 没有提供前端构建产物，使用 Node.js 22 构建：

```sh
npm ci --prefix core/crud-admin
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

确认生成文件位于 `dist/admin/`，Apache 对外路径保留 `/admin/`。

Composer 可能报告已知的上游 PSR-4 警告：`src/Promotion/Exception/PromotionException.php` 中的 `App\Promotion\PromotionException` 被跳过，不会加入优化自动加载映射。检查命令退出码和 `core/crud-skeleton/vendor/autoload.php`。不要为了消除该警告而在生产环境安装 Composer 开发依赖；应跟进上游修正命名空间/路径。

## 3. 创建生产密钥和环境配置

在 release 之外创建受保护的环境文件，并链接到：

```text
<项目根目录>/integration/backend/.env.prod.local
```

使用仅供生产环境的唯一配置，最小示例：

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<唯一随机密钥>
REFRESH_TOKEN_SECRET=<另一个唯一随机密钥>
DATABASE_URL="mysql://<应用账号>:<URL编码密码>@127.0.0.1:3306/<数据库名>?serverVersion=<实际版本>&charset=utf8mb4"
DEFAULT_URI=https://example.com
JWT_PRIVATE_KEY_PATH=/www/secure/<应用>/keys/private.pem
JWT_PUBLIC_KEY_PATH=/www/secure/<应用>/keys/public.pem
JWT_PASSPHRASE=
APP_SHARE_DIR=/www/secure/<应用>/data
INVENTORY_ENABLED=0
ALIYUN_SMS_DRY_RUN=1
```

仅在实际启用邮件、Redis 和外部服务时配置对应变量。使用专用 MySQL 账号，不要使用 `root`；不要将 MySQL 暴露到公网。数据库密码中的特殊字符必须 URL 编码。

### 只生成一次 JWT 密钥

只有在生产密钥尚不存在时才执行。更换密钥可能导致已签发令牌失效。

```sh
KEY_DIR=/www/secure/<应用>/keys
sudo install -d -o root -g www -m 0750 "$KEY_DIR"
sudo test ! -e "$KEY_DIR/private.pem" && sudo test ! -e "$KEY_DIR/public.pem"
sudo openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$KEY_DIR/private.pem"
sudo openssl pkey -in "$KEY_DIR/private.pem" -pubout -out "$KEY_DIR/public.pem"
sudo chown root:www "$KEY_DIR/private.pem" "$KEY_DIR/public.pem"
sudo chmod 0640 "$KEY_DIR/private.pem" "$KEY_DIR/public.pem"
```

将 `www` 替换为实际 PHP-FPM 组。PHP-FPM 只能读取私钥，不能修改私钥；安全备份私钥。

## 4. 仅为本站选择生产环境

集成 bootstrap 会在加载环境专属 dotenv 文件**之前**选择 `APP_ENV`。仓库中的 `integration/backend/.env` 默认值为 `dev`；因此只在 `.env.prod.local` 中写 `APP_ENV=prod` 不会切换运行环境。请求环境必须在 bootstrap 前已包含 `APP_ENV=prod` 和 `APP_DEBUG=0`。

Apache 应在本站虚拟主机配置中设置，不要改多个站点共用的 PHP-FPM pool：

```apache
SetEnv APP_ENV prod
SetEnv APP_DEBUG 0
```

若宝塔为 HTTP/HTTPS 分别生成虚拟主机，两边都要按需设置。确认当前 Apache/PHP-FPM 配置会传递这些变量；否则为本站创建独立 FPM pool，不要修改共享 pool。CLI 命令也要显式设置相同环境变量。

## 5. 精确配置 `open_basedir`

应用 bootstrap 需要读取 `core/`、`business/`、`integration/`，所以 PHP 必须能读取整个项目目录；还需要访问项目外的密钥/数据路径。Apache 的 `DocumentRoot` 仍然只指向 `integration/backend/public`，放宽 PHP 文件读取白名单不等于把整个仓库公开到 Web。

宝塔站点白名单示例（按实际路径调整）：

```ini
open_basedir=/www/wwwroot/<项目根目录>/:/www/secure/<应用>/:/tmp/
```

不要全局关闭 `open_basedir`。宝塔可能给站点 `.user.ini` 添加不可变属性；编辑前先备份并检查：

```sh
FILE=/www/wwwroot/<项目根目录>/integration/backend/public/.user.ini
sudo cp -a "$FILE" "$FILE.bak"
sudo lsattr "$FILE"
```

只有当输出包含 `i` 属性时，才临时执行 `sudo chattr -i "$FILE"`，修改白名单后恢复 `sudo chattr +i "$FILE"`。若原先没有不可变属性，就不要额外设置。切勿使用 `chmod 777`。

## 6. 配置 Apache 2

在宝塔本站的 Apache 虚拟主机中保留本站生成的 PHP 8.5 handler，并合并以下路由规则，替换路径和域名。若 HTTP、HTTPS 使用不同虚拟主机，按需分别配置。

```apache
<VirtualHost *:443>
    ServerName example.com
    # 保留宝塔为本站生成的 TLS/证书指令。
    DocumentRoot "/www/wwwroot/<项目根目录>/integration/backend/public"

    SetEnv APP_ENV prod
    SetEnv APP_DEBUG 0

    RedirectMatch 301 ^/admin$ /admin/

    <Directory "/www/wwwroot/<项目根目录>/dist/admin">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /admin/index.html
    </Directory>

    <Directory "/www/wwwroot/<项目根目录>/integration/backend/public">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>

    # 保留宝塔为本站生成的 PHP 8.5 handler。
</VirtualHost>
```

两条 fallback 分别服务管理前端 SPA 和 Symfony 后端路由。不要将项目根目录、`.env*`、`vendor/`、`core/`、`business/` 或 `var/` 设为 Web 根目录。重载前使用宝塔配置检查/重载功能或服务器 Apache 配置检查命令验证。

部署脚本会创建 `integration/backend/public/admin` 相对符号链接，指向 `dist/admin`。不要再配置 `Alias /admin/`；Apache 应从 public 目录提供该链接的内容。请保留 `FollowSymLinks`。重复更新见下方[服务器更新脚本](#使用部署脚本重复更新)。

### 使用部署脚本重复更新

在服务器项目 checkout 中，以 root 执行：

```sh
cd /www/wwwroot/<项目根目录>
PHP_BIN=/www/server/php/85/bin/php bash scripts/deploy.sh --all
```

脚本必须以 root 执行：Git、Composer、npm 和 Symfony bundle 静态资源安装由 root 运行；数据库迁移及缓存命令通过 `runuser` 切换为 `PHP_FPM_USER`（默认 `www`）。按服务器实际情况修改 `PHP_BIN`，必要时设置 `PHP_FPM_USER`/`PHP_FPM_GROUP`。checkout 必须没有已跟踪文件的本地修改，并位于 `main` 分支；其他分支通过 `DEPLOY_BRANCH` 指定。宝塔生成的未跟踪文件会保留；若更新会覆盖这类文件，Git 会拒绝快进。按需选择模式：

```sh
# 仅前端：不安装 Composer 依赖、不迁移数据库、不清理后端缓存
PHP_BIN=/www/server/php/85/bin/php bash scripts/deploy.sh --frontend

# 仅后端：安装 Composer 依赖、执行迁移、安装 Symfony 资源并清理缓存
# 先备份数据库；迁移不会再要求交互确认。
PHP_BIN=/www/server/php/85/bin/php bash scripts/deploy.sh --backend

# 前后端全部更新，并执行迁移（先备份数据库）
PHP_BIN=/www/server/php/85/bin/php bash scripts/deploy.sh --all
```

所有模式都会快进更新同一个 Git checkout，因此无论选择哪个模式，Git 都会更新所有已跟踪代码；参数只控制依赖安装、构建和迁移步骤。前端会先构建到暂存目录，成功后才发布到 `dist/admin`；构建失败时旧前端保持不变。目录替换时会有短暂间隙，并非完全原子操作。

后端和全部更新模式都会自动执行待处理迁移，不再要求交互确认。运行任一模式前，必须先完成并验证数据库备份可恢复。

此脚本会原地更新正在运行的 checkout：更新期间后端 PHP 文件会逐步生效，应选择低流量时段。前端会先构建再替换，但后端不是原子发布，也没有自动回滚。如需更强的版本隔离，请使用[发布与回滚 runbook](release-and-rollback.zh-cn.md)。

## 7. 仅授权运行时目录

集成 Kernel 使用以下缓存路径。生产 Monolog 将日志写到 `php://stderr`，不会写入 `var/log/backend` 下的文件：

```text
<项目根目录>/var/cache/backend/<环境>/
```

缓存目录位于仓库根目录下，但不在 Web 文档根目录中。Symfony 会自动创建 `prod/translations` 等子目录。生产错误日志会输出到 PHP-FPM worker stderr；请检查宝塔 Apache/PHP-FPM 错误日志，若看不到输出，再核实是否捕获 stderr。只有缓存和配置的 `APP_SHARE_DIR` 需要 PHP-FPM 写权限。若宝塔 PHP-FPM 用户/组确实为 `www:www`：

```sh
ROOT=/www/wwwroot/<项目根目录>
sudo install -d -o www -g www -m 0750 \
  "$ROOT/var/cache/backend"
sudo chown -R www:www "$ROOT/var/cache/backend"
sudo chmod -R u+rwX "$ROOT/var/cache/backend"
sudo install -d -o www -g www -m 0750 /www/secure/<应用>/data
```

替换为 PHP-FPM 实际用户。不要把整个仓库 `chown` 给 Web 用户；PHP-FPM 对源码和 `vendor/` 只需要读取权限。

## 8. 备份、迁移、创建管理员并预热缓存

在项目根目录使用 PHP 8.5 CLI，并提供生产环境。先检查 console 和迁移状态：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console about
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:status
```

先完成并验证 MySQL 备份，再执行已审核的迁移：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

之后用生产集成控制台显式创建管理员，不要运行开发初始化器：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console \
  app:identity:user:create <邮箱> <用户名> '<一次性强密码>' --admin
```

密码作为 CLI 参数，可能短暂出现在 shell 历史/进程列表。请在受控 SSH 会话操作，并在首次登录后修改临时密码。

使用生产环境清理/预热缓存。确保 CLI 和 FPM 对缓存的属主/组访问兼容；如果 CLI 以部署用户创建了缓存文件，只在运行时缓存目录恢复 PHP-FPM 所需权限。

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console cache:clear
```

## 9. 冒烟验证与日常维护

重载 Apache/PHP-FPM 后检查：

- `https://<域名>/admin/` 及管理前端嵌套路由能加载 SPA 和资源。
- API 路由能访问 Symfony，并连接生产数据库。
- 登录、管理员授权、日志、上传/共享数据和已启用集成正常。
- 公网无法读取环境文件、密钥、`vendor/` 或源码。

定期备份数据库和上传文件；生产密钥不可写入 CI 构建日志。重复发布见[发布与回滚](release-and-rollback.zh-cn.md)，常见故障见[生产故障排查](production-incident-triage.zh-cn.md)。
