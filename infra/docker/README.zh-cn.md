# Docker 部署基础设施

这是项目自有的部署基础方案。它从仓库根目录构建已组合业务模块的管理前端和 Symfony 集成应用，并运行 PHP-FPM、Nginx、MySQL 和 Redis。`core/crud-skeleton/` 下的上游 Docker 文件仅供参考；本方案不修改，也不依赖其应用入口、环境变量布局或 Nginx 文档根目录。`make dev` 用于宿主机本地开发，不会配置此部署 Compose 服务栈。

## 前置条件与首次启动

安装 Docker Engine 和 Docker Compose 插件。在仓库根目录执行：

```sh
cp infra/docker/.env.example infra/docker/.env
# 编辑 infra/docker/.env，替换所有占位值。
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml config --quiet
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml up --build -d
```

生成专用的 `APP_SECRET` 和 `REFRESH_TOKEN_SECRET`，两者必须不同。按照示例使用十六进制 MySQL 密码，避免自动生成的 `DATABASE_URL` 需要额外 URL 编码。生成该部署专用的 RSA JWT 密钥对，在 `.env` 中填写绝对路径，并限制宿主机文件权限，确保私钥仅管理员和 Docker 可读取。不要使用 `make env-init` 生成的密钥，也不要提交本地 `.env`。

Nginx 默认绑定 `127.0.0.1:8080`；应在其前方配置宿主机反向代理或负载均衡器并提供 TLS。管理前端通过 `/admin/` 提供，后端路由由 integration front controller 处理。MySQL 和 Redis 端口不映射到宿主机。数据库、应用运行数据和上传文件保存在持久化命名卷中。

worker 默认不启动；如需消费 `async` Messenger transport，可显式启用：

```sh
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml --profile workers up --build -d
```

只有在配置并验证相关 transport 和应用 handler 后才启用 worker。本方案不会启动上游 scheduler、配置外部邮件/短信/微信服务、终止 TLS，也不会自动执行 schema 迁移。

## 运维命令

```sh
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml ps
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml logs -f app nginx
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml exec app \
  php integration/backend/bin/console about
docker compose --env-file infra/docker/.env -f infra/docker/compose.yaml exec app \
  php integration/backend/bin/console doctrine:migrations:status
```

执行 schema 变更前，应先检查迁移状态并备份数据。请使用 integration 控制台，不要运行 core 控制台。`docker compose down` 会保留命名卷。`docker compose down -v` 会永久删除数据库、上传文件和运行时数据卷；仅当确认这些数据可以丢弃时才可执行。

## 构建方式与限制

`infra/docker/Dockerfile` 使用 Node.js 22 构建管理前端静态资源，使用 PHP 8.5 安装生产依赖，再分别生成 PHP-FPM 和 Nginx 镜像。仓库根目录 `.dockerignore` 排除本地环境文件、上游 core 环境文件、依赖目录、构建产物和测试 fixture。Compose 要求提供真实密钥及密钥文件路径，不使用不安全默认值。

此方案是起点，并非已完成安全审计的一键生产部署。为确保发布可复现，应按 digest 固定基础镜像；同时配置宿主机防火墙、TLS、监控、备份，审查存储持久性和上传访问控制，并测试升级与迁移回滚。采用前请运行本地组合应用和后端检查。更多部署概念见 [DEPLOY.md](../../DEPLOY.md)，简体中文指南见[快速开始](../../QUICKSTART.zh-cn.md)。
