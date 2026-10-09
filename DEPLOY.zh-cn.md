# 非容器化部署指南

本指南介绍在常规 Linux 服务器上部署本项目，不使用 Docker。前端作为静态文件提供服务，Symfony API 通过 PHP-FPM 和生产级 Web 服务器运行。请按服务器操作系统调整服务管理器和 Web 服务器配置。项目自有的 Docker/Nginx 基础设施见 [`infra/docker/README.md`](infra/docker/README.md)，其简体中文说明见 [`infra/docker/README.zh-cn.md`](infra/docker/README.zh-cn.md)。

## 1. 生产架构与前置条件

安装满足 `core/crud-skeleton/composer.json` 要求（`>=8.4`，本指南建议使用 PHP 8.5）的 PHP、PHP-FPM、Composer 2、用于前端构建的 Node.js 22/npm，以及 Nginx 或 Apache。准备生产级关系型数据库（上游生产模板使用 MySQL，具体支持情况取决于选用的迁移）、TLS 终止服务，以及启用的外部集成所需的出站网络访问。不要默认认为本地 SQLite 数据库适用于生产。

安装 Composer 和已启用功能要求的 PHP 扩展。至少确认 `ctype`、`iconv`、`openssl`、`intl`、`mbstring`、`curl`、`fileinfo`、`xml`/`dom`、`zip` 和所选数据库对应的 PDO 驱动（例如 `pdo_mysql`）。CLI PHP 与 PHP-FPM 必须使用相同的 PHP 版本和扩展：

```sh
php -v
php -m
composer --version
node --version
npm --version
```

使用专用的非 root 发布用户和 PHP-FPM 运行用户。入站流量仅开放给 Web 服务器；不要将 PHP-FPM、数据库或 Redis 端口暴露到公网。PHP 内置服务器（`make backend`）仅供开发使用，不可用于生产。

## 2. 构建发布版本

部署包含 Git 子树、项目代码和锁文件的不可变发布目录。尽可能将密钥、上传内容和可写运行数据放在源码目录之外，并使用 `current` 符号链接指向当前发布版本。

安装生产依赖时，不安装 Composer 开发依赖，也不运行自动脚本：

```sh
composer install \
  --working-dir=core/crud-skeleton \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts

npm ci --prefix core/crud-admin
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

管理前端构建产物位于 `dist/admin/`，生产环境基础路径为 `/admin/`。Web 服务器的静态文件映射必须保留此路径。除非明确决定公开源码映射，否则不要使用 `--sourcemap` 构建对外发布的生产版本。

后端集成控制台入口为 `integration/backend/bin/console`。始终使用该入口，而不是 core 控制台，以确保加载集成 Kernel 和业务模块注册器。仅安装依赖不会初始化生产应用，也不会运行数据库迁移。

## 3. 配置生产环境变量和密钥

集成 bootstrap 加载 `integration/backend/.env` 以及 Symfony 对应环境的文件；它不会加载 `core/crud-skeleton/.env*`。生产配置可通过进程环境变量提供，或写入未纳入版本控制且权限受限的 `integration/backend/.env.prod.local`。对于按发布目录管理的部署，可将实际密钥文件存放在发布目录之外，再链接到对应位置，例如：

```sh
install -d -o root -g www-data -m 0750 /etc/ns-ultimate
install -o root -g www-data -m 0640 /secure/source/backend.env /etc/ns-ultimate/backend.env
ln -s /etc/ns-ultimate/backend.env /srv/ns-ultimate/releases/<release>/integration/backend/.env.prod.local
```

提供 dotenv 格式的配置文件，并替换以下所有占位值：

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<唯一且足够长的随机值>
REFRESH_TOKEN_SECRET=<与APP_SECRET不同的唯一随机值>
DATABASE_URL="mysql://<user>:<url-encoded-password>@<private-db-host>:3306/<database>?serverVersion=<supported-version>&charset=utf8mb4"
DEFAULT_URI=https://<public-domain>
JWT_PRIVATE_KEY_PATH=/etc/ns-ultimate/keys/private.pem
JWT_PUBLIC_KEY_PATH=/etc/ns-ultimate/keys/public.pem
JWT_PASSPHRASE=
ACCESS_TOKEN_TTL=3600
REFRESH_TOKEN_TTL=2592000
OTP_TTL=300
OTP_REDIS_DSN=redis://<private-redis-host>:6379
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
OUTBOX_PUBLISH_INTERVAL=5
MAILER_DSN=<production-mailer-dsn>
MEDIA_STORAGE_DEFAULT=local
APP_SHARE_DIR=/srv/ns-ultimate/shared/data
INVENTORY_ENABLED=0
ALIYUN_SMS_DRY_RUN=1
```

Bootstrap 会在加载环境专属 dotenv 文件之前，根据进程环境确定 `APP_ENV`。仓库中的 `integration/backend/.env` 默认值是 `dev`，因此仅在 `.env.prod.local` 中写 `APP_ENV=prod` 不足以切换生产模式。必须为此站点的 Web/PHP-FPM 请求环境设置 `APP_ENV=prod` 和 `APP_DEBUG=0`，CLI 命令也要显式设置。不要把这两个变量写入多个站点共用的 PHP-FPM pool。

数据库版本字符串和凭据 URL 编码必须与实际数据库相匹配。根据实际启用的功能配置 Redis、邮件、短信、微信、支付和库存相关变量。除非上游生产验证及业务需求明确批准启用此预览功能，否则保持 `INVENTORY_ENABLED=0`。只有在凭据、模板、配额和投递行为均完成验证后，才将 `ALIYUN_SMS_DRY_RUN` 设为 `0`。仅当确实启用微信/支付服务时，才通过密钥管理系统或进程环境提供对应凭据。完整的上游集成变量列表见 [`core/crud-skeleton/.env.prod.example`](core/crud-skeleton/.env.prod.example)；不要直接照搬其中面向 Docker 的值。

使用经批准的密钥生成流程，为部署单独生成 JWT 密钥对。以下为生成未加密 RSA 密钥对的基本示例：

```sh
install -d -o root -g www-data -m 0750 /etc/ns-ultimate/keys
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
  -out /etc/ns-ultimate/keys/private.pem
openssl pkey -in /etc/ns-ultimate/keys/private.pem -pubout \
  -out /etc/ns-ultimate/keys/public.pem
chown root:www-data /etc/ns-ultimate/keys/*.pem
chmod 0640 /etc/ns-ultimate/keys/private.pem /etc/ns-ultimate/keys/public.pem
```

仅向确实需要读取私钥的应用运行用户开放权限，并安全备份密钥；制定密钥轮换计划（轮换可能使现有签发令牌失效）。不要在生产环境运行 `make env-init`、`make dev` 或 `make dev-reset`：开发启动会初始化本地数据库、执行迁移并创建开发管理员；重置会删除已登记的开发数据库。切勿将 `.env.prod.local`、密钥文件、数据库凭据或服务商密钥提交到版本控制。

集成 Kernel 将缓存放在仓库级的 `var/cache/backend/`。生产 Monolog 会把 JSON 日志写入 `php://stderr`，而不是 `var/log/backend/prod.log`；生产主 handler 使用 `fingers_crossed`，发生错误时才输出缓冲记录（404/405 除外）。要查看应用日志，请检查本站 Apache/PHP-FPM 错误日志，并确认 FPM 会捕获 worker stderr。开发环境才使用 `var/log/backend/` 下的文件日志。只需为 PHP-FPM 配置缓存目录的写权限，同时确保应用源码和密钥不可被运行用户修改。为上传内容及当前存放于 `var/data/` 的数据选择持久化存储方案；发布目录中的临时文件不构成可靠的数据持久化或备份策略。向 Web 服务器直接开放上传目录前，请先检查 core 的媒体配置。

## 4. 验证配置、预热缓存并执行迁移

以发布用户身份执行命令，并确保生产变量已提供；也可以通过链接的 `.env.prod.local` 加载：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console about
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console debug:router
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:status
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console cache:clear
```

应用任何数据库变更前，检查数据库主机和 schema、待执行迁移、数据库备份和回滚方案。迁移应作为明确的发布步骤，由获授权的操作人员在确认备份有效后执行：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

迁移完成后，使用生产集成控制台显式创建管理员；不要使用开发初始化脚本：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console \
  app:identity:user:create <邮箱> <用户名> '<一次性强密码>' --admin
```

密码作为命令行参数传入，可能短暂出现在本机进程列表或 shell 历史中。请在受控的运维会话执行，使用强临时密码，并在首次登录后修改。

不要在每次 PHP-FPM worker 启动时自动运行迁移。数据库账号只应拥有应用运行和已批准迁移所必需的权限。切换流量前，检查路由表并验证应用相关接口。

## 5. 配置 Web 服务器和 PHP-FPM

将 PHP-FPM 配置为仅使用 `integration/backend/public/index.php` 作为 PHP 入口。后端文档根目录设置为 `<release>/integration/backend/public`；切勿将仓库根目录、`core/`、`.env*`、`vendor/` 或 `var/` 作为 Web 内容公开。根据实际环境配置 TLS、请求体大小限制、超时、可信代理头和安全响应头。

以下为 Nginx 配置示例（请替换路径、域名和 FPM socket，并通过 `nginx -t` 检查）：

```nginx
server {
    listen 443 ssl;
    server_name example.com;

    root /srv/ns-ultimate/current/integration/backend/public;
    index index.php;

    # 静态管理前端构建产物。Vite 生产基础路径为 /admin/。
    location = /admin {
        return 301 /admin/;
    }
    location /admin/ {
        root /srv/ns-ultimate/current/dist;
        try_files $uri $uri/ /admin/index.html;
    }

    # 后端请求统一交由 integration front controller 处理。
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # 禁止执行文档根目录中的任意 PHP 文件。
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /srv/ns-ultimate/current/integration/backend/public/index.php;
        fastcgi_param DOCUMENT_ROOT /srv/ns-ultimate/current/integration/backend/public;
        fastcgi_param APP_ENV prod;
        fastcgi_param APP_DEBUG 0;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

对于 Apache 2，站点文档根目录仍应指向 `integration/backend/public`，并保留宝塔为该站点生成的 PHP 8.5 处理器；在该站点的虚拟主机中合并以下规则。示例使用 Apache 标准 `mod_alias` 和 `mod_dir` 模块：

```apache
<VirtualHost *:443>
    ServerName example.com
    DocumentRoot "/srv/ns-ultimate/current/integration/backend/public"

    # 仅在此虚拟主机启用生产环境，不影响共享 FPM pool 中的其他站点。
    SetEnv APP_ENV prod
    SetEnv APP_DEBUG 0

    RedirectMatch 301 ^/admin$ /admin/

    <Directory "/srv/ns-ultimate/current/dist/admin">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /admin/index.html
    </Directory>

    <Directory "/srv/ns-ultimate/current/integration/backend/public">
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>

    # 保留宝塔为此站点配置的 PHP 8.5/FPM 处理器；应用入口仅为
    # integration/backend/public/index.php。
</VirtualHost>
```

宝塔生成的虚拟主机和 PHP 处理器指令会因安装方式而异。请把规则合并到正确站点的 HTTP/HTTPS 虚拟主机，不要整体覆盖配置。确认 Apache 会把站点级 `SetEnv` 传给 PHP-FPM；若不能传递，应为本站创建独立 FPM pool，而不是修改其他应用共用的 pool。此站点的 `open_basedir` 必须允许读取整个项目根目录（bootstrap 需要访问 `core/`、`business/` 和 `integration/`），以及项目外的密钥/共享数据目录。不要全局关闭限制。宝塔可能会给 `.user.ini` 设置不可变属性；先备份并用 `lsattr` 检查，临时执行 `chattr -i` 后只修改所需路径，完成后用 `chattr +i` 恢复。切勿使用 `chmod 777`。

此 Apache 示例假设部署时会创建 `integration/backend/public/admin` 符号链接，指向 `dist/admin`；不要同时配置 `Alias /admin/`，否则 Alias 会覆盖文档根目录下的符号链接。请为 public 目录启用 `FollowSymLinks`。

请根据安装的 Nginx 或 Apache/PHP-FPM 版本以及应用实际静态资源、上传路由确认所选配置。若 TLS 在可信上游代理处终止，请明确配置 Symfony 的可信代理；不要信任任意转发头。根据启用功能使用进程管理器运行所需的 Messenger worker 或 outbox 定时任务，并确保这些进程与 PHP-FPM 使用相同发布版本和环境配置。

## 6. CI/CD 发布流程

CI 应检出完整仓库（包括 Git 子树），按锁文件安装依赖、运行测试，并使用 Node.js 22 构建 `dist/admin/`。通过受限的 SSH/发布账号，将经过测试的同一提交/构建产物部署到新的不可变 release 目录；不要把生产凭据放入 CI 构建任务或提交到 Git。尽可能将 `.env.prod.local`、JWT 密钥、上传文件和可写运行数据放在 release 目录之外。在服务器使用 PHP 8.5 CLI 安装 Composer 生产依赖，验证生产环境，备份数据库，作为明确发布步骤审核/执行迁移，然后原子切换 `current`。不要在每个 Web worker 启动时自动迁移。保留上一 release 和数据库备份以便回滚；仅回滚代码未必能撤销 schema 变更。单目录原地更新可使用 [`scripts/deploy.sh`](scripts/deploy.sh)，它会快进拉取指定分支并构建前后端，但后端不是原子切换。

## 7. 切换流量、验证和回滚

启用新版本前，确认前端构建、Composer 安装、环境变量验证、数据库连通性、缓存预热和已批准的数据库迁移均已成功。原子切换 `current` 符号链接，并按需重新加载 PHP-FPM/Web 服务器，然后检查：

- `https://<domain>/admin/` 返回管理前端页面及其资源；
- 配置的 `/api` 路由可访问 Symfony 集成 Kernel；
- 健康检查、认证、日志、上传和已启用的外部回调符合预期；
- 未登录访问和管理员权限边界符合预期。

验证通过前保留上一版本和数据库备份。若需要回滚，切回代码符号链接并重新加载相关服务。数据库迁移可能不可逆；应遵循迁移专属的回滚或向前修复方案，不要认为只回滚代码就能恢复 schema 兼容性。密钥及凭据轮换必须经过明确且已测试的流程。

## 部署检查清单

- [ ] PHP CLI 和 FPM 的版本/扩展满足 Composer 与运行时要求。
- [ ] TLS、私有数据库/Redis 连通性、备份和服务访问控制均已配置。
- [ ] 生产环境变量和 JWT 密钥存储在版本控制之外，并遵守最小权限原则。
- [ ] 站点专属 Web/FPM 环境设置为 `APP_ENV=prod`、`APP_DEBUG=0`，且没有修改共享 pool。
- [ ] 管理前端构建产物通过 `/admin/` 提供；后端 Web 根目录仅为 `integration/backend/public/`。
- [ ] 已准备运行时可写目录和持久化上传存储。
- [ ] 已通过 integration 控制台清理并预热生产缓存。
- [ ] 已检查迁移、完成备份，并将数据库迁移作为显式发布步骤执行。
- [ ] 已验证外部服务、worker、健康检查、授权、日志和回滚流程。

## 宝塔/PHP 部署排错

- **Node.js：**使用 Node.js 22，与仓库 CI 和 Docker 构建版本一致。Node 只用于构建管理前端；静态文件构建完成后可由 CI 上传 `dist/admin/`，服务器不必用 Node 提供网站服务。
- **Composer PSR-4 警告：**部分上游版本会提示 `src/Promotion/Exception/PromotionException.php` 中的 `App\Promotion\PromotionException` 与 `App\` PSR-4 路径不匹配，并从优化自动加载映射中跳过该类。这与安装失败不是一回事：检查 Composer 退出码以及 `core/crud-skeleton/vendor/autoload.php` 是否生成。该类路径/命名空间应在上游修正；不要通过在生产环境安装开发依赖来掩盖警告。
- **`open_basedir` 报 `vendor/autoload.php` 不存在：**文件可能实际存在，但 PHP-FPM 无权读取。宝塔白名单可按实际路径加入 `/www/wwwroot/<项目目录>/`、`/www/secure/<项目目录>/` 和 `/tmp/`。允许本站 PHP 进程读取项目根目录（bootstrap 需要 `core/`、`business/` 和 `integration/`）及项目外的密钥/数据目录。网站文档根目录仍应是 `integration/backend/public`；不要全局关闭 `open_basedir`，也不要使用 `777` 权限。宝塔可能给站点 `.user.ini` 设置不可变属性；先备份并用 `lsattr` 检查，仅在编辑白名单时执行 `chattr -i`，完成后若原先设置了不可变属性，再执行 `chattr +i` 恢复。
- **`composer install --no-dev` 后报缺少 `DebugBundle`：**生产进程必须实际使用 `APP_ENV=prod`、`APP_DEBUG=0`。项目 `integration/backend/.env` 默认环境是 `dev`，必须在 dotenv bootstrap 之前通过本站请求/FPM 环境指定环境。Apache 可在本站虚拟主机用 `SetEnv`，Nginx 可传本站专属 FastCGI 参数。不要修改多个站点共用的 FPM pool；若 Web 服务器无法传递本站变量，请创建独立 pool。
- **缓存 `Permission denied` 或找不到 `prod.log`：**Kernel 将缓存写入 `<项目根目录>/var/cache/backend/<环境>`，Symfony 会自动创建 `prod/translations` 等子目录。生产 Monolog 将 JSON 写入 `php://stderr`，不会写入 `var/log/backend/prod.log`；主 handler 在错误发生时才输出记录（404/405 除外）。请检查宝塔本站 Apache/PHP-FPM 错误日志；若 FPM 未捕获 worker stderr，检查本站 pool 的 `catch_workers_output`，修改共享 pool 前要考虑其他应用。只给实际 PHP-FPM 用户授予缓存目录和配置的 `APP_SHARE_DIR` 写权限，不要把整个仓库改为 `www` 所有或可写。
- **JWT 密钥权限：**生产 RSA 密钥应单独生成并存放在公开文档根目录之外；PHP-FPM 用户只需读取，不应有写权限。安全备份私钥；更换密钥会使现有令牌失效。
