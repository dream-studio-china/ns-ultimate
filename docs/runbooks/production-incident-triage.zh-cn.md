# Runbook：生产故障排查

## 首要响应

1. 记录发生时间、受影响域名/路由、当前 release SHA，以及最近的部署/配置变更。
2. 检查宝塔 Apache 和 PHP 8.5-FPM 错误日志。生产 Monolog 写入 `php://stderr`，默认不会创建 `var/log/backend/prod.log`。日志位置由面板配置决定。
3. 保留日志和故障 release。不要运行开发重置命令，也不要大范围修改属主/权限。
4. 若故障紧随发布出现，参照[发布与回滚](release-and-rollback.zh-cn.md)处理；先确认数据库迁移是否兼容旧代码。

## Composer 安装出现 PSR-4 警告

部分上游版本可能出现：

```text
Class App\Promotion\PromotionException located in
src/Promotion/Exception/PromotionException.php does not comply with psr-4 ... Skipping.
```

这表示 Composer 未把该类加入优化自动加载映射；单凭此警告不能判定安装失败。立即检查 Composer 退出码，并确认 `core/crud-skeleton/vendor/autoload.php` 存在。当前项目源码没有其他位置引用该类；应跟进上游修正命名空间/路径。不要通过生产安装开发依赖作为规避办法。

## `open_basedir` 提示 `vendor/autoload.php` 不存在

自动加载文件可能实际存在，但 PHP-FPM 无权读取。应用 bootstrap 还会加载 `core/`、`business/` 和 `integration/`；只允许访问 `integration/backend/public` 不够。

- Apache `DocumentRoot` 仍保持 `<项目>/integration/backend/public`。
- 为本站白名单增加项目根目录及准确的外部密钥/数据路径，例如 `<项目目录>/:<密钥数据目录>/:/tmp/`。
- 不要全局关闭 `open_basedir`。
- 宝塔可能给 `.user.ini` 设置不可变属性。先备份并用 `lsattr` 检查；若存在不可变属性，临时 `chattr -i` 后编辑，再用 `chattr +i` 恢复。不要使用 `chmod 777`。

## 使用 `--no-dev` 后找不到 `DebugBundle`

生产依赖有意不安装开发 bundle。集成 bootstrap 会先选运行环境再加载 dotenv，仓库 `.env` 默认 `APP_ENV=dev`。

- 确保实际 Web 请求在 bootstrap 前已设置 `APP_ENV=prod` 和 `APP_DEBUG=0`。
- Apache 在本站虚拟主机设置；Nginx 传本站专属 FastCGI 参数。
- 不要将变量写入其他站点共用的 PHP-FPM pool。若 Web 服务器无法传递变量，为本站创建独立 pool。
- CLI 检查也要显式设置 `APP_ENV=prod APP_DEBUG=0`。

## Symfony 无法创建 `var/cache/...` 或看不到应用日志

集成 Kernel 将缓存写入公开文档根目录之外的项目级 `var/cache/backend/<环境>`。Symfony 会自动创建 `prod/translations` 等子目录。生产 Monolog 将 JSON 日志写入 `php://stderr`，默认不写入 `prod.log`。主 `fingers_crossed` handler 在发生错误时才输出缓冲记录，且忽略 404/405。

1. 确认 PHP 8.5-FPM 的实际用户/组；宝塔常见为 `www`，但必须核实。
2. 只给 `var/cache/backend` 和配置的 `APP_SHARE_DIR` 写权限；除非显式更改了生产日志配置，否则 `var/log/backend` 不是生产 Monolog 的输出位置。
3. 检查所有上级目录的遍历（execute）权限。
4. 检查宝塔本站 Apache/PHP-FPM 错误日志。若 FPM 未捕获 worker stderr，检查本站 pool 的 `catch_workers_output`。修改共享 pool 前要考虑其他应用。
5. 不要让 Web 用户拥有或写入整个仓库。如果 CLI 预热缓存使用了不同属主，只修复运行时缓存目录。

## 数据库连接被拒绝或连错数据库

- 核实生产 `DATABASE_URL` 的主机、端口、schema 和 MySQL `serverVersion`。
- 区分宿主机发布端口和容器内部端口。宿主机上的进程与 Docker 网络内的进程可能需要不同主机名/端口。
- 确认数据库仅对应用主机私网开放，凭据只可访问指定 schema。
- 不要用 `make dev` 测试生产数据库连接；开发初始化可能创建 schema 并执行迁移。用显式生产配置执行只读连通性/console 检查。
- 执行迁移前检查 `doctrine:migrations:status` 并确认备份可恢复。

## JWT 私钥无法读取

确认 `JWT_PRIVATE_KEY_PATH` 指向预期生产密钥，PHP-FPM 能遍历父目录并读取文件。密钥应在公开文档根目录外，由管理员拥有，且 PHP-FPM 无写权限。不要把重新生成密钥当作排障捷径；轮换可能使现有令牌失效。

## 管理员登录或创建账号

生产管理员应在迁移后通过集成控制台显式创建：

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console \
  app:identity:user:create <邮箱> <用户名> '<一次性强密码>' --admin
```

命令行密码可能出现在进程列表/历史中。请使用受控会话，并在首次登录后更换临时凭据。生产环境绝不能运行开发初始化器。

## 结束处理

恢复后验证 `/admin/`、API/认证、数据库写入、日志和已启用集成。记录原因、具体变更、release/迁移编号和预防措施。事故记录必须去除凭据、令牌、cookie 和个人数据。
