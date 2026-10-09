# 快速开始

本指南帮助你在本地运行组合后的管理前端和 API。非容器化服务器部署请参阅 [DEPLOY.md](DEPLOY.md) 和[简体中文部署指南](DEPLOY.zh-cn.md)。Docker Compose 部署基础方案见 [`infra/docker/README.md`](infra/docker/README.md) 及其[简体中文说明](infra/docker/README.zh-cn.md)。

## 1. 安装本地开发环境

建议按本指南使用 PHP 8.5。当前后端 Composer 配置要求 PHP `>=8.4`；CLI、PHP-FPM（如本地使用）及 PHP 扩展应使用相同且受支持的版本。需要安装：

- Git
- PHP 8.5 CLI，以及 OpenSSL、PDO、PDO SQLite；如使用 MySQL 初始化，还需 PDO MySQL；另需 ctype、iconv、intl、mbstring、XML/DOM、curl、fileinfo、tokenizer、zip 扩展
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

是否需要 Redis，取决于启用的功能和配置。基础本地配置使用 SQLite，无需单独安装数据库服务，但不会初始化数据库 schema，且不能保证所有上游模块或迁移都兼容 SQLite。若需完整初始化的 MySQL 本地环境，请安装/启用 `pdo_mysql`，并使用下文的 `make dev-init`。

## 2. 克隆仓库并安装依赖

```sh
git clone <repository-url> ns-ultimate
cd ns-ultimate
make install
```

`make install` 根据 core 自有的 npm 锁文件安装管理前端依赖，并根据 Composer 锁文件安装后端依赖。该命令会禁用 Composer 自动脚本；根目录 npm 配置仅用于编排安装。

如果 Composer 提示缺少扩展，请为 `php -v` 显示的同一个 PHP 安装对应扩展；不要通过 `--ignore-platform-reqs` 绕过检查。如果机器上安装了多个 PHP 版本，请检查 `which php`、`which composer` 和 `composer diagnose`。

## 3. 初始化本地配置并启动

```sh
make env-init
make env-check
make dev
```

管理前端地址为 `http://127.0.0.1:9528`；API 默认监听 `http://127.0.0.1:8000`，Vite 会将 API 请求代理到后端。`env-init` 会创建被 Git 忽略的本地覆盖配置、随机开发密钥和仅供开发使用的 JWT 密钥对。它不会覆盖已存在的本地文件。请勿将这些配置或密钥用于部署。

若要连接本地 MySQL 并创建可用的管理员账号，可使用 `make dev-init`，无需手动编辑 `DATABASE_URL`：

```sh
make dev-init
make env-check
make dev
```

脚本会询问 MySQL 主机/端口/用户名（默认 `127.0.0.1:3306`、`root`），密码输入时不会显示；随后询问数据库名（默认 `ns_ultimate`）和管理员身份（默认 `admin@example.com` / `admin`）。显示目标并确认后，脚本会在数据库不存在时创建数据库、执行待处理的项目迁移，并仅在该管理员身份尚未被占用时创建账号。不会重置已有管理员。初始密码随机生成，保存在权限为 `0600` 的 `var/keys/admin-initial-password.txt`；请在本机读取并于首次登录后修改。数据库连接写入 Git 忽略的 `integration/backend/.env.local`。

如需更换端口：

```sh
make dev ADMIN_PORT=9529 BACKEND_PORT=8001
```

更改后端端口时，还需在 `integration/admin/.env.local` 中覆盖 `VITE_PROXY_TARGET`，例如：`VITE_PROXY_TARGET=http://127.0.0.1:8001`。

## 4. 运行检查

```sh
make test-workflow   # 环境和 Vite 配置测试
make test-admin      # 管理前端组合层和 core 前端测试（本地命令）
make test-backend    # 后端集成测试（使用隔离的临时 SQLite 数据库）
make test            # 运行以上所有测试
make type-check
make build
```

`make test-backend` 使用临时 SQLite 数据库，并在测试结束时清理；它不会迁移或连接本地开发数据库。`make migrate-status` 和 `make migrate` 是针对当前应用数据库的显式操作；执行前务必确认数据库目标。

GitHub Actions 会运行项目 workflow/admin 组合测试、后端集成/业务测试和组合管理前端构建；不会运行上游 core 自带的测试套件。

## 5. 调试与常用命令

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
