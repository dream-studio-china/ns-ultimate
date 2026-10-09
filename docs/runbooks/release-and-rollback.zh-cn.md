# Runbook：生产发布与回滚

## 当前自动化边界

当前 `.github/workflows/ci.yaml` 使用 Node.js 22/PHP 8.5 执行项目检查、组合测试、后端测试并构建管理前端；它**不会**上传构建产物，也**不会**部署到宝塔。本文描述的是由运维人员控制的发布流程。只有在配置好受保护的部署凭据、环境审批和等价检查后，才应自动化部署。

## 发布前提

- 已审核提交通过 CI，且目标仓库中存在该提交。
- 服务器有非 root 发布账号、PHP 8.5 CLI/FPM、Composer 2 和必要扩展。
- 生产环境文件和 JWT 密钥已在 release 目录之外准备好。
- Apache/PHP-FPM 已按站点设置 `APP_ENV=prod`、`APP_DEBUG=0`、正确的 `open_basedir`、Web 根目录和运行时可写路径。
- 操作者已有经验证的数据库备份，并确认待执行迁移是否向后兼容。
- `current` 已指向已知可用版本；首次部署时按专门流程创建。

## 1. 标识并准备 release

使用唯一 release ID（优先使用 commit SHA）创建新目录，不要原地覆盖正在运行的版本。

```sh
BASE=/srv/ns-ultimate
RELEASE_ID=<已审核的commit-sha>
RELEASE="$BASE/releases/$RELEASE_ID"
```

确认 `RELEASE` 未包含其他部署内容。部署已审核提交及其 Git 子树。CI 构建产物可提供源码和 `dist/admin/`；除非产物有意包含与服务器平台兼容的 `vendor/`，否则服务器仍需安装生产 Composer 依赖。

若在服务器构建，使用 PHP 8.5 CLI 和 Node 22：

```sh
composer install \
  --working-dir="$RELEASE/core/crud-skeleton" \
  --no-dev --prefer-dist --no-interaction --no-progress \
  --optimize-autoloader --no-scripts

npm ci --prefix "$RELEASE/core/crud-admin"
npm run build --prefix "$RELEASE/core/crud-admin" \
  -- --config "$RELEASE/integration/admin/vite.config.ts"
```

若 CI 产物已包含管理前端，则检查 `$RELEASE/dist/admin/index.html` 和资源，不必重复构建。切勿把生产密钥放入构建任务。

## 2. 链接共享配置和运行时数据

生产环境文件和数据放在 release 之外，并链接到新版本：

```sh
ln -s /etc/ns-ultimate/backend.env \
  "$RELEASE/integration/backend/.env.prod.local"
```

站点 Web 请求环境还必须单独提供 `APP_ENV=prod` 和 `APP_DEBUG=0`；单靠 `.env.prod.local` 无法在 bootstrap 前选择环境。确认 Apache 虚拟主机或本站 FPM 配置指向当前 release，且 PHP 进程能读取环境文件和密钥。

Kernel 使用项目根目录的 `var/cache/backend/`。生产 Monolog 写入 `php://stderr`，不需要 `var/log/backend/prod.log`；请检查 PHP-FPM/Apache 错误日志捕获。使用 release 目录时，可为每个 release 配置缓存，或将缓存路径链接到共享可写目录。`APP_SHARE_DIR` 应位于持久化路径，不应放在可丢弃的 release 中。检查并保留已有非空目录内容，切勿未经检查就将其替换为符号链接。

## 3. 验证、备份并迁移

在候选 release 根目录使用 PHP 8.5 CLI 和生产环境：

```sh
cd "$RELEASE"
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console about
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:status
```

核对目标数据库和迁移列表。迁移前完成数据库备份，并验证备份可读/可恢复。然后作为明确的发布操作执行迁移：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

不要在 Apache/PHP-FPM 启动钩子里自动迁移。不要默认 schema 可以安全回滚；迁移不可逆时应采用经过验证的向前修复。

## 4. 预热缓存并检查候选版本

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console cache:clear
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console debug:router
```

确认缓存/日志属主允许 PHP-FPM 写入。切流前验证管理前端构建、`.env.prod.local` 可读、密钥可读、数据库连通和预期路由。

## 5. 原子切换流量

保留旧的 `current` 目标。在同一父文件系统创建唯一临时符号链接，再重命名覆盖 `current`：

```sh
NEXT="$BASE/current.next.$RELEASE_ID"
ln -s "$RELEASE" "$NEXT"
mv -Tf "$NEXT" "$BASE/current"
```

仅在 `current` 是符号链接且 `NEXT` 不存在时执行。确认宝塔 Apache 虚拟主机使用稳定的 `$BASE/current/...` 路径。必要时重载/重启本站 PHP-FPM pool 清除旧 OPcache；只有 Apache 配置变化时才需重载 Apache。

## 6. 冒烟验证并记录

检查 HTTPS 管理页面及资源、API 健康检查/认证、数据库业务功能、日志、上传和已启用的外部集成。检查 Apache、PHP-FPM 和应用日志。记录 release SHA、迁移版本、备份编号、操作者和健康检查结果，不要记录秘密。

## 回滚

只有旧版本与当前数据库 schema 兼容时，才仅回滚代码指针：

```sh
PREVIOUS_RELEASE=<已知可用的release路径>
NEXT="$BASE/current.rollback.$(date -u +%Y%m%dT%H%M%SZ)"
ln -s "$PREVIOUS_RELEASE" "$NEXT"
mv -Tf "$NEXT" "$BASE/current"
```

必要时重载本站 PHP-FPM pool，然后重复冒烟检查。若新迁移不向后兼容，**不要**假设切回旧代码就能恢复服务。遵循迁移专属的恢复/向前修复方案；只有获得明确批准后才能恢复数据库备份，因为恢复会丢弃备份之后产生的数据。

事故复盘完成前不要删除失败版本、日志或备份。不要使用 `git reset --hard`、`docker compose down -v`、`make dev-reset` 或大范围递归删除作为发布回滚手段。
