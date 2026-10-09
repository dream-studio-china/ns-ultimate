# 运维 Runbook

本目录提供可按步骤执行的部署和运维流程，用于补充架构文档与部署参考。

- [宝塔 + Apache 生产部署](baota-apache-production.zh-cn.md) ([English](baota-apache-production.md)) — 在宝塔 Linux 主机上首次部署 Apache 2、PHP 8.5-FPM 和 MySQL。
- [发布与回滚](release-and-rollback.zh-cn.md) ([English](release-and-rollback.md)) — 使用独立 release 目录、执行迁移、切换流量和回滚。
- [生产故障排查](production-incident-triage.zh-cn.md) ([English](production-incident-triage.md)) — 排查 Composer、环境、`open_basedir`、权限、数据库和密钥问题。

## 范围与安全

- 示例中的占位符都要替换为实际域名、路径、运行账号、数据库版本和 PHP-FPM socket；不要把真实密钥写入 Git 或工单。
- 不要对生产环境运行 `make dev`、`make dev-reset`、`make docker-dev` 或 `make docker-dev-reset`。
- 数据库 schema 变更前必须备份。迁移是明确的发布操作，不应挂在 Web worker 启动流程中。
- Web 根目录仅指向 `integration/backend/public`；管理前端通过 `/admin/` 单独提供。
- 当前 GitHub Actions workflow 会验证和构建项目，但**不会部署到宝塔**。在安全配置并验证新的部署 job 前，不要称其为自动 CD。

另见[规范部署指南](../../DEPLOY.zh-cn.md)及[英文版](../../DEPLOY.md)。
