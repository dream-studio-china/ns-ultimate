# 快速开始

本指南帮助你在本地运行组合后的管理前端和 API，并说明如何启动 `apps/` 下的独立应用。非容器化服务器部署请参阅 [DEPLOY.md](DEPLOY.md) 和[简体中文部署指南](DEPLOY.zh-cn.md)。Docker Compose 部署基础方案见 [`infra/docker/README.md`](infra/docker/README.md) 及其[简体中文说明](infra/docker/README.zh-cn.md)。

## 1. 安装本地开发环境

如果不想在主机安装 PHP、Composer 和 MySQL，请使用[Docker 开发环境](docs/operations/development.md#docker-php-and-mysql-development)；该方式只需要 Docker Compose 和 Node.js/npm。

建议按本指南使用 PHP 8.5。当前后端 Composer 配置要求 PHP `>=8.4`；CLI、PHP-FPM（如本地使用）及 PHP 扩展应使用相同且受支持的版本。需要安装：

- Git
- PHP 8.5 CLI，以及 OpenSSL、PDO、PDO MySQL；另需 ctype、iconv、intl、mbstring、XML/DOM、curl、fileinfo、tokenizer、zip 扩展
- Composer 2
- Node.js 22 LTS 及其对应的 npm
- `make`（多数 macOS/Linux 开发环境已预装）

确认终端使用的是预期版本：

```sh
php -v
php -m
composer --version
node --version
npm --version
make --version
```

macOS 用户可使用 Homebrew 安装：

```sh
brew install php@8.5 composer node@22
```

安装后，确认 `php`、`composer`、`node` 和 `npm` 都指向预期版本。若使用版本管理器，请在仓库终端中选择 PHP 8.5 和 Node.js 22。Linux 和 Windows 用户可使用适合所在系统的软件包或版本管理器，并参考 PHP、Composer 和 Node.js 的[官方安装指南](https://www.php.net/manual/en/install.php)、[Composer 安装指南](https://getcomposer.org/download/)和 [Node.js 下载页面](https://nodejs.org/en/download)。Windows 环境建议使用 WSL2；以下命令应在 WSL 中运行，而不是 PowerShell。

是否需要 Redis，取决于启用的功能和配置。本机 `make dev` 使用 MySQL，因为完整项目迁移集不兼容 SQLite；请先启动本机 MySQL。如果不想安装 MySQL，请使用上文链接的 Docker 开发流程。

## 2. 克隆仓库并安装依赖

```sh
git clone <repository-url> ns-ultimate
cd ns-ultimate
make install
```

`make install` 根据 core 自有的 npm 锁文件安装管理前端依赖，并根据 Composer 锁文件安装后端依赖。该命令会禁用 Composer 自动脚本；根目录 npm 配置仅用于编排安装。`apps/` 下的独立应用使用各自的锁文件，需按下文单独安装依赖。

如果 Composer 提示缺少扩展，请为 `php -v` 显示的同一个 PHP 安装对应扩展；不要通过 `--ignore-platform-reqs` 绕过检查。如果机器上安装了多个 PHP 版本，请检查 `which php`、`which composer` 和 `composer diagnose`。

## 3. 启动本机开发环境

```sh
make dev
```

首次运行 `make dev` 时，会初始化开发专用密钥、询问 MySQL 连接和管理员身份、执行迁移，并在管理员不存在时创建账号。修改数据库前必须输入完整的 `主机:端口/数据库名` 目标。初始化成功后只执行一次；后续 `make dev` 直接启动两个应用，不会重复初始化或自动执行迁移。管理前端地址为 `http://127.0.0.1:9528`；API 默认监听 `http://127.0.0.1:8000`，Vite 会将 API 请求代理到后端。

MySQL 默认主机/端口为 `127.0.0.1:3306`，用户为 `root`，数据库为 `ns_ultimate`；输入密码时不会显示。管理员默认身份为 `admin@example.com` / `admin`。已有管理员不会被重置。每次运行 `make dev`，都会在 Vite 启动前显示保存的管理员邮箱和初始随机密码。密码保存在权限为 `0600` 的 `var/local-dev/keys/admin-initial-password.txt`；如果之后修改过密码，显示的只是初始密码。开发专用配置和数据库连接保存在 Git 忽略的 `integration/backend/.env.dev.local`，与生产配置分离。

```sh
make dev-reset
make dev
```


重置前会验证数据库的开发归属令牌，拒绝生产 `APP_ENV`，且只连接 loopback 主机上名称完全匹配的开发数据库。操作会显示配置的连接目标和 MySQL 服务端身份，必须输入完整目标后才继续。本机 Docker 容器发布端口时，服务端身份可能与宿主机不同；数据库归属由 checkout 专属令牌验证。重置仅触及隔离的开发环境文件和 `var/local-dev/` 文件。

如需更换端口：

```sh
make dev ADMIN_PORT=9529 BACKEND_PORT=8001
```

更改后端端口时，还需在 `integration/admin/.env.local` 中覆盖 `VITE_PROXY_TARGET`，例如：`VITE_PROXY_TARGET=http://127.0.0.1:8001`。

## 4. 启动独立应用

`apps/` 下的应用与组合后的管理前端／API 相互独立。`make dev` 不会启动它们，`make install` 也不会安装它们的依赖。

### Store Console（移动员工工作台）

```sh
npm ci --prefix apps/store-console
npm run dev --prefix apps/store-console
```

打开 `http://127.0.0.1:5180/`。真实员工入口默认将 `/api` 代理到 `http://127.0.0.1:8000`，因此请先通过 `make dev` 或 `make backend` 启动后端。如需使用其他可信的开发后端，在 `apps/store-console/.env.local` 中设置 `STORE_CONSOLE_API_TARGET` 并重启 Vite。登录、门店成员身份和权限均由后端校验。

`http://127.0.0.1:5180/?mode=demo` 是隔离演示；其顾客付款入口（`payment.html?session=<演示场次 ID>`）仅用于演示，不会连接任何支付渠道。`npm run build --prefix apps/store-console` 会构建两个入口；`npm run preview --prefix apps/store-console` 在 `http://127.0.0.1:4180` 预览构建结果，但不代理 API。

### Cashier（Electron 桌面演示）

```sh
npm ci --prefix apps/cashier
npm run dev --prefix apps/cashier
```

`dev` 会启动 Electron，并由 Vite 在 `http://127.0.0.1:5178` 提供渲染进程。该应用是明确的演示：不连接后端认证、不调用门店 API，也不处理真实收款。仅做浏览器 UI 调试时，改用 `npm run dev:web --prefix apps/cashier`。

### 管理端助手

助手没有独立服务端，已组合进管理前端。使用 `make dev` 启动组合应用后打开 `http://127.0.0.1:9528`。如需禁用，在 `integration/admin/.env.local` 中设置 `VITE_ADMIN_AGENT_ENABLED=false`，然后重启 Vite 或重新构建。提供商、模型和 API 文档地址在助手界面中配置。

## 5. 运行检查

```sh
make test-workflow   # 环境和 Vite 配置测试
make test-admin      # 管理前端组合层和 core 前端测试（本地命令）
make test-backend    # 后端集成测试（使用隔离的临时 SQLite 数据库）
make test            # 运行以上所有测试
make type-check
make build
```

安装依赖后，可运行独立应用检查：

```sh
npm test --prefix apps/store-console && npm run build --prefix apps/store-console
npm test --prefix apps/cashier && npm run build --prefix apps/cashier
```

浏览器套件需要各应用安装 Playwright Chromium：先执行 `npx playwright install chromium`，再在 `apps/store-console` 或 `apps/cashier` 目录中运行 `npm run test:e2e`。

`make test-backend` 使用临时 SQLite 数据库，并在测试结束时清理；它不会迁移或连接本地开发数据库。`make migrate-status` 和 `make migrate` 是针对当前应用数据库的显式操作；执行前务必确认数据库目标。

GitHub Actions 会运行项目 workflow/admin 组合测试、后端集成/业务测试、组合管理前端构建，以及独立的 store-console 和 cashier UI 检查；不会运行上游 core 自带的测试套件。

## 6. 调试与常用命令

```sh
make help
make console ARGS="about"
make routes ARGS="business-dummies-list"
make container ARGS="--parameter=kernel.logs_dir"
make logs
make backend-debug   # 当前 PHP 安装必须包含 Xdebug
make preview         # 预览生产构建的管理前端
```

环境文件优先级、数据库选择、API 代理和 Xdebug 配置请参阅[本地开发与调试指南](docs/operations/development.md)。部署配置和发布步骤请参阅 [DEPLOY.md](DEPLOY.md) 或[简体中文部署指南](DEPLOY.zh-cn.md)。
