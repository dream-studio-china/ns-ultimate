# 全局审计实现方案

**状态：押后；仅为提案，尚未实现或启用。** 截至 2026-11-15，继续使用现有轮转
`audit.log`；目前不计划单独审计数据库、归集进程或查看后台。只有在确实需要结构化
跨请求查询、更长期留存或更强审计保证时，再重新评估本提案。

押后不代表现有日志覆盖所有业务事件，也不代表当前脱敏完整。实际覆盖范围和限制见
[API 审计日志指南](../operations/audit-logging.md)。

本文不授权安装依赖、修改数据库或线上配置，也不授权发起支付、退款、打印或重放历史任务。

[English canonical version](global-audit-implementation.md)

## 1. 决策与线上保护原则

建立项目统一的审计事件词汇、上下文、脱敏策略和查询边界：

- 使用 **AuditorBundle / DoctrineProvider** 处理经过批准的 ORM 实体变更历史。
- 使用现有 **Monolog** 处理尽力而为的操作事件。
- 不重新开发通用 ORM 审计框架，不把审计改造成事件溯源或第二套资金账本。

第一版优先保证线上连续运行，不在支付、退款、订单结算、库存或出票事务中新增同步审计写入。

上线必须保持以下不变量：

1. 不修改 `core/`，包括 Composer 文件、映射及实体属性标记。
2. 现有 API 路由、返回结构、权限、支付通道名称、金额、模板语法、出票数量与幂等行为不变。
3. 不增加支付服务商请求，不自动重试、不新增扣款、退款或打印。
4. 支付及退款记录仍是权威数据；审计不能把支付标成成功、释放 Invoice、应用退款或修改打印任务。
5. 新采集、归集和查看功能分别默认关闭；缺少审计功能不能阻止现有 HTTP 请求或 worker 启动。
6. 不把补采数据伪装成历史事实；明确记录覆盖起点、关闭时段及缺口。

“最小侵入”指变更范围有界、可回退、可测量，**不是承诺零开销或零风险**。
组件原生事务审计存在失败耦合，不能宣传成透明、绝对不阻塞的增强功能。

## 2. 已核对的现状与约束

| 现有实现 | 对方案的约束 |
| --- | --- |
| [API 审计监听器](../../integration/backend/src/EventListener/ApiAuditLogListener.php) 已记录请求、调用者、报文和异常 | 复用上下文与脱敏，不再增加另一套请求报文记录器 |
| [Monolog 审计配置](../../integration/backend/config/packages/monolog.yaml) 使用 JSON 轮转文件，保留 30 份 | 保留原排障通道；新增脱敏业务事件流，不偷偷改变原日志结构 |
| [汇付客户端](../../business/backend/HuifuFin/src/Client/HuifuClient.php) 和回调控制器已有日志 | 当前原始报文也需要经过统一脱敏边界 |
| 已有支付尝试、退款和回调事件记录 | 复用编号及状态，不重复实现支付流程或资金账本 |
| [员工指令服务](../../business/backend/Store/src/Service/StaffCommandService.php) 在事务内保存操作回执 | 复用回执；重复指令返回旧结果不能记成第二次业务成功 |
| [打印请求写入器](../../business/backend/Printer/src/Service/OrderPrintRequestWriter.php) 使用直接 DBAL SQL | ORM 监听不能自动覆盖全部出票流程 |
| [集成 Kernel](../../integration/backend/src/Kernel.php) 保留 core 项目目录并导入项目配置 | 需要明确增加集成层 bundle 注册接缝；只放一个 integration bundles 文件不会自动生效 |
| [Bootstrap](../../integration/backend/bootstrap.php) 同时加载 core 与 integration 的 Composer autoloader | 除依赖解析外，必须验证运行时到底由哪套 vendor 提供共享类 |
| [Dockerfile](../../infra/docker/Dockerfile) 明确安装 core 依赖，integration 另有自己的清单 | 必须验证干净发布镜像中两套依赖的安装，不能依赖开发机复制进去的 vendor |

现有风险见 [API 审计运行指南](../operations/audit-logging.md)：查询参数、嵌套的服务商字符串和部分凭证字段尚未完整脱敏。
本文不表示这些问题已经解决。

## 3. 组件选择与依赖准入

本方案核对的稳定候选版本：

| 组件 | 候选版本 | 职责 |
| --- | --- | --- |
| `damienharper/auditor-bundle` | `7.2.1` | Symfony 集成及可选实体历史查看器 |
| `damienharper/auditor` | `4.3.1` | 审计编排 |
| `damienharper/auditor-doctrine-provider` | `1.2.0` | ORM 变更集、审计表及读取器 |
| 现有 Monolog | 保持当前锁定版本 | 有界操作事件输出 |

这些是评估候选，不是安装浮动最新版的指令。PHP >= 8.4、Symfony 8、Doctrine ORM 3 / DBAL 4 的版本约束基本匹配项目，但实施时仍需按当时的锁文件、PHP 扩展和发布镜像实际解析。

依赖变更仅放在 `integration/backend/composer.json` 及其锁文件：

- 不更新 core 清单、core vendor 内容或 core Symfony Flex recipes。
- 不用大范围 `replace` 假装依赖已兼容。
- 验证 integration autoloader 不会用不同版本覆盖 core 的 Symfony、Doctrine 或 PSR 类。
- 无法证明依赖归属安全时，停止 bundle 阶段，保留现有 Monolog 模式，另行审批依赖组合方案。
- 验证 `--no-dev` 镜像、`intl`、缓存预热、CLI、worker 和 HTTP 启动。
- 从 integration 增加受控 bundle 注册，并保留父 Kernel 的 bundle 列表；不改 core `config/bundles.php`，不通过 recipe 暴露开发查看器。

## 4. 两类审计，不混淆保证

### 4.1 操作事件：第一阶段线上模式

增加薄的 `AuditRecorder` 边界，提供 no-op 实现和有界 JSON / Monolog 输出。
生产者只提交允许的标量字段，不序列化整个实体；不能使用业务 EntityManager 保存审计。
可选的独立归集进程把事件投影到审计数据库，供后台查询。

记录器不得开启事务、flush/clear 业务 EntityManager、触发懒加载、进行网络请求、调用支付或改变响应。
序列化及输出异常在记录器内部隔离，通过不会递归写审计的计数器和安全信号报告。

本地文件写入仍有延迟；必须测试慢盘、满盘、限制事件大小，并在影响响应时停用采集。
**捕获异常不等于能够限制阻塞中的文件系统调用耗时。**

该模式尽力记录：进程崩溃、输出中断或缓冲区满可能丢事件，不构成“完整且原子”的合规历史保证。
HTTP 请求及服务商回调不等待归集进程完成。

### 4.2 Auditor 原生 ORM 历史：独立准入

组件可捕获 ORM flush 中的新增、修改、删除和支持的关系变更；不能自动捕获查询、权限拒绝、无变更的重复操作、直接 SQL、批量 DQL 或外部服务商操作。
实体及忽略字段从外部配置登记，不修改实体源码。

**同连接：** 审计 SQL 参与业务事务，失败可能回滚原操作。
不能 catch 住失败的 `flush()` 就继续提交，因为事务或 EntityManager 可能已经不可用。

**独立连接／数据库：** 仍然是同步工作，而且失去原子回滚关系；可能出现原业务回滚但审计已写入，或业务成功但审计缺失。
这不是免费获得的 fail-open 或 outbox。

因此：

1. 先用隔离测试数据评估原生模式。
2. 第一阶段线上 ORM 审计实体清单为空；支付、Invoice、退款、库存、结算和打印处理不接同步原生审计。
3. 后续仅在明确接受事务失败耦合、延迟和建表行为后，批准少量低频配置实体试点。
4. 如果要求审计故障连后台配置保存都不能影响，则这些实体也不启用原生写入，只用操作事件并标注覆盖限制。
5. 严格原子的资金历史需另行决策。事务性 outbox 同样会向资金事务增加写入与故障点，不纳入本次连续运行优先的首版。

## 5. 目录与职责

以下均为拟议文件和接口，并非已存在的扩展点。

| 路径 | 职责 |
| --- | --- |
| `business/backend/Audit/src/` | 事件格式、记录接口、脱敏规则、实体策略、查询权限 |
| `business/backend/Audit/config/services.yaml` | 业务服务及 no-op 默认值，使用现有模块发现 |
| `business/backend/Audit/migrations/` | 获批后新增投影表的版本化迁移 |
| `integration/backend/src/Audit/` | 请求／CLI 上下文、Auditor 注册、Monolog 输出适配，不放业务规则 |
| `integration/backend/config/` 与 integration Kernel | 条件注册，初始空实体清单，不导入公共查看器路由 |
| 现有 Huifu、Printer、Store 模块 | 在已确认的业务边界补少量显式事件 |
| `business/admin/` | 可选只读审计后台，权限由服务端强制执行 |
| `infra/` 与项目部署脚本 | 干净安装依赖、持久化文件、轮转及可选归集调度 |

不新增实体继承链，不包装所有控制器／服务，不替换默认 EntityManager，不扫描并标记全部 core 实体。
不为插日志重构支付流程；优先复用回执及已有事件，仅增加不改变事务边界的小型显式接入点。

## 6. 事件格式与信任边界

所有模块共用带版本的事件格式：

| 字段 | 约束 |
| --- | --- |
| `schemaVersion`、`eventId`、`eventName` | 版本化名称；事件 UUID 由服务端生成；名称有长度上限 |
| `occurredAt`、`recordedAt`、`source` | UTC；来源为 HTTP、CLI、worker、服务商回调或查单 |
| `traceId`、`parentEventId` | 关联编号，不是授权凭证 |
| `actorType`、`actorId` | 已认证用户、服务身份或 `unknown`；不快照用户名和邮箱 |
| `storeUuid` | 从可信权限／订单归属取得；只有明确全局事件可为空 |
| `subjectType`、`subjectId`、`references` | 订单、Invoice、支付尝试、退款、指令、打印任务／规则／模板的允许编号 |
| `outcome`、`commitState` | 区分观察到的结果与已证实提交的事实 |
| `amountMinor`、`currency` | 整数最小货币单位与校验过的币种，不解析自由文本猜金额 |
| `fromState`、`toState`、`reasonCode` | 有界允许值，不直接记录异常堆栈 |
| `changes` | 允许的标量差异或脱敏摘要，不保存整个记录 |
| `verification`、`operationKey` | 可信校验结论及安全关联引用，不复制内部 proof 或密钥 |

初始建议上限：单事件 16 KiB、64 个变化字段、单安全文本 256 字符、16 个关联引用。
超限输出带省略标记的安全摘要，错误路径不能重新序列化原对象；不得截断关键编号后仍假装语义完整。

主追踪编号由服务端生成；外部 `X-Request-Id` 经长度和字符校验后仅作辅助关联。
操作人和门店不信任请求 JSON。每个请求／worker 消息结束后重置上下文，防止跨人、跨店串号。

归集重试保持原 `eventId`，按该字段去重。不同观察使用不同事件 ID，但可共享 `operationKey`。
不能按订单 UUID 去重，因为一个订单允许有多次合法付款／退款；也不能假设请求编号唯一。

## 7. 覆盖清单与事务语义

### 7.1 首批事件

| 范围 | 拟议事件 | 说明 |
| --- | --- | --- |
| HTTP／安全 | `operation.rejected`、`audit.accessed`、`audit.exported` | 已知上下文及结果，不重复记录请求正文 |
| 支付 | `payment.attempt_created`、`payment.submission_observed`、`payment.result_unknown`、`payment.confirmation_observed` | 复用支付尝试和 Invoice；汇付受理不是付款完成 |
| 回调 | `provider.notification_rejected`、`provider.notification_duplicate`、`provider.notification_applied` | 区分收到、校验和应用 |
| 退款 | `refund.requested`、`refund.confirmation_observed`、`refund.core_application_observed` | 申请金额、汇付确认金额、本地入账分别记录 |
| 补偿 | `payment.query_observed`、`payment.close_observed`、`refund.reconcile_observed` | 记录操作人／调度来源及原始引用，不能发起新扣款 |
| 打印 | `print.request_observed`、`print.submission_observed`、`print.result_unknown` | 数量快照、渲染页数、份数；不复制整张小票，也不保证物理出纸 |
| 配置 | `configuration.changed` | 安全字段变化、版本／摘要、操作人和作用域，不记录凭证 |

重点配置包括商户绑定、通道开关和默认方式、模板定义和出票模式、打印规则份数及启停。
模板 JSON 优先记录批准的结构变化、节点数、出票模式和摘要，不复制付款链接或订单渲染全文。

### 7.2 不把观察当提交事实

- Doctrine `postFlush` 不代表外层事务已提交；HTTP 200、方法返回、workflow 事件或 `kernel.terminate` 也不能通用地证明提交。
- 原生同连接 Auditor 记录具有事务耦合，与尽力而为的操作观察分开标识。
- 只有明确知道最外层事务完成的业务边界，才可标记 `commitState = committed`。
  无法证明时标记 `observed`／`unknown`，不能为了提升标签而给热路径增加锁或查询。
- 回滚不能记录成已提交的付款成功或退款入账；可以记录“尝试后失败／未知”。
- 不为审计回调另开第二个业务事务，资金变更仍走原来的已验签流程。
- `StaffOperation` 回执和回调去重继续复用；直接 SQL 打印、CLI 和 worker 需要显式覆盖或明确排除。

后台区分“观察到”“服务商已验证确认”“系统已记录完成”。
有无审计事件都不能单独作为到账证明，更不能据此重试未知支付。

## 8. 隐私、脱敏与排障连续性

统一策略用于 API、汇付日志、显式事件及可选 ORM 差异，在输出前脱敏。
组件的 `ignored_columns` 是额外防线，不能替代递归报文处理。

- 优先按事件白名单输出；排除密码及散列、私钥、凭证密文、Token／Cookie、验证码、微信 secret／AES／API 密钥、OpenID／买家身份、内部 proof 和完整支付调起数据。
- 兼容大小写、camelCase、snake_case、连字符键名；有界处理字符串内部的 JSON／表单，包括 `wx_data`、`alipay_data`、`resp_data`，先脱敏再截断。
- 保留允许的服务商错误码、已验证状态、耗时、接口名称和关联号，不直接输出完整异常信息或签名报文。
- 查询串、请求头、回跳地址和二维码内容均视为可能携带凭证；未知键舍弃，自由文本原因脱敏。
- 凭证修改只记录“发生变更”和版本，不记录旧值、新值或低熵秘密的摘要。
- 非法、超大或二进制输入只记录安全类型／长度摘要，不回退输出原文。

去除原始服务商报文会影响排障证据，因此上线前必须验证安全字段仍足以定位回调拒绝，并更新现有运行指南。
若确需原始取证，应另行审批加密、严格权限、短期保存机制；本方案不默认开启。

旧日志可能已含凭证，应限制访问并按现有流程核查、轮换已确认泄露的秘密。
此次功能不自动批量删除历史日志、不批量轮换凭证，也不上传原始样本。

## 9. 存储、迁移、查询与权限

第一阶段使用单独轮转的本地 JSON 事件文件，无需新建审计表即可启用。
灰度前验证目录权限和持久化；不能假设容器替换后 PHP-FPM 文件仍存在。

可选归集进程通过独立连接写入只追加的 `audit_event` **查询投影**，不使用业务 EntityManager。
拟议字段包括唯一 `event_id`、`schema_version`、事件／来源／时间、操作人／作用域、主体／关联、结果／提交标识、有界脱敏 JSON 和入库时间。

初始索引：`(store_uuid, recorded_at, event_id)`、`(subject_type, subject_id, recorded_at)`、`trace_id`。
跨主体关联有需要时再加有界引用索引；不向原业务表增加外键或索引。

归集采用有界批次、检查点、事件 ID 幂等插入，覆盖轮转、截断行、非法记录、重启和积压上限。
投影提交后才确认消费；安全隔离坏记录，监控积压时长、丢弃量、磁盘占用和失败。
延迟或丢失应显示为覆盖限制，不能静默展示成完整历史。

Auditor 原生表通常按实体建表，并非组件天然提供一张通用事件表。
保留其支持的格式，通过只读适配器与显式事件统一展示；维护表前先关闭对应原生采集。
审计表及归集过程本身不参与递归实体审计。

迁移必须是经审查的项目自有增量迁移，由专用部署凭证执行：

- 不启动时自动 DDL，不跑全库 schema update。
- 不修改已执行迁移，不破坏性回滚，不重写历史业务数据。
- 检查 schema diff 不会把组件管理的审计表误判为应删除对象。

默认关闭组件查看器，不导入 vendor 路由。获批后增加业务只读 API／后台：

- 拟议权限为全局 `audit:read`、门店级 `store:audit:read`，导出另设权限，复用现有授权。
- 列表、总数、详情、关联查询及导出都必须先做门店限制；空作用域的全局事件不默认对门店开放。
- 历史权限使用可信的历史门店归属，不能按当前可变实体的所属门店重新推断，包括实体已删除的情况。
- 不提供通用 CRUD 改删、公共入口、任意 JSON 查询或可编辑状态字段；审计访问／导出也记事件，但不递归。
- 游标分页、日期范围、页大小及导出配额有界，所有内容以不可信文本渲染。

保留期属于运维政策，不是资金历史保证。初期保留现有诊断日志策略，投影保留期另行审批。
清理仅涉及审计自有数据且按小批次执行；需要时支持法律保留，清理权限独立于查看权限。
应用层只追加不等于数据库防篡改，更强的合规存储／签名需另行威胁建模。

## 10. 开关、上线与回退

拟议独立开关：`capture`、`collector`、`viewer`、`native_orm`，初始全部关闭。
用门店／实体／事件白名单缩小范围；批准覆盖的资金成功／失败事件不做概率抽样，关闭时段明确记录。

采集开关必须在组装事件数据前检查。
原生 listener／bundle 注册属于容器构建期配置，仅改环境变量不足以改变已有缓存或常驻 worker。
发布需预热匹配缓存、重启相关 worker，不能按每个 HTTP 请求改变编译后的服务图。

| 阶段 | 内容及通过条件 |
| --- | --- |
| 0：基线 | 盘点 ORM／SQL／CLI／服务商路径；测延迟、错误及资金／打印调用次数；批准字段和威胁模型 |
| 1：安全基础 | 补现有日志脱敏和关联，证明诊断仍可用且 API 语义不变 |
| 2：依赖评估 | 干净镜像安装，采集／查看器关闭；验证类归属、启动和数据库兼容，不通过则停止 |
| 3：操作事件灰度 | 单个测试门店／操作人启用非事务事件；不启用同步资金审计、不向外部服务商重放 |
| 4：归集及查看 | 增量投影表和独立归集；验证重放、权限、覆盖显示及有界查询，再开放只读后台 |
| 5：可选 ORM 灰度 | 单独批准低频实体、表结构及失败耦合风险；资金／打印热路径继续排除，不能接受耦合则跳过 |
| 6：扩展 | 逐步扩门店／事件，明确预算、保留期和告警，记录激活时间及未覆盖路径 |

每阶段使用相同业务输入，与关闭审计的部署对照。
建议初始停止阈值：任何审计引起的业务失败、金额／出票／服务商调用次数变化、跨店泄露，或持续 p95 延迟增加超过 5%。
实际阈值需运维按基线批准，这不是现有 SLA。

回退步骤：

1. 按受支持方式关闭采集及原生 listener；构建期配置不能热切换时，用已验证旧镜像／缓存并重启相关进程。
2. 归集或导出导致负载时单独停止，查看器可独立关闭。
3. 依赖／启动故障恢复旧代码、锁文件及镜像。
4. 保留新增审计表／文件供调查，不执行 down 迁移，也不为回退审计而改业务数据。
5. 核对支付／退款状态、打印数量和正常操作，记录停采缺口及原因。

任何回退都不重试资金操作、不重置未知支付、不更换幂等键、不重打历史任务。

## 11. 验证与验收

使用隔离数据和模拟服务商；不把线上支付、退款、打印或凭证管理请求当测试样本。

| 范围 | 必须提供的证据 |
| --- | --- |
| 默认关闭 | 无新增审计表依赖、路由、查询、实体 listener 或外部副作用；原 API／worker 正常 |
| 构建与依赖 | core／integration 干净安装、no-dev 镜像、运行时类文件归属、锁稳定、无 core diff |
| 隐私 | 嵌套 JSON／表单、query／header、键名变体、非法／超大输入、凭证更新、异常内容均不泄露秘密测试标记 |
| 上下文 | 外部追踪号、匿名回调、CLI／服务身份、连续 worker 消息及跨店隔离 |
| 业务等价 | 采集开关前后金额、状态、扣减、锁、请求及出票数量一致；微信／支付宝、旧过滤值和模板兼容 |
| 回调与退款 | 非法／重复／迟到通知、未知结果、金额不符、查单和“汇付确认但本地未入账”不改变原语义 |
| 事务 | 外层回滚、嵌套 flush、提交失败不能变成已提交成功事件；明确展示原生审计的回滚耦合 |
| 故障隔离 | 满盘／慢盘／只读盘、序列化错误、归集故障／积压、缺投影表、重启不触发业务重放 |
| 原生模式 | 缺审计表、数据库异常／死锁和 ORM 故障；业务事务失败时不得声称 fail-open |
| 覆盖 | 直接 SQL 打印、被拒绝／无变化操作、HTTP／CLI／队列明确覆盖或标注排除 |
| 查询 | 总数／分页／详情／导出之前限作用域，覆盖已删除主体、全局记录、XSS 和有界查询 |
| 运维 | 开关、缓存变化、worker 重启、检查点／轮转恢复及不改库不重放的回退演练 |

按实际实现运行项目声明的后端／前端检查；后端使用隔离 SQLite 测试，并在 MySQL 预发布环境补事务、DDL、锁和性能验证。
不能把 SQLite 测试通过解释为线上并发及性能已验证。文档使用 [链接检查器](../operations/documentation.md)。

上线验收需覆盖矩阵、依赖／迁移审查、隐私测试、等价与故障注入结果、性能预算、开关／回退演练、权限保留期和运维指南。
只报告实际执行的验证及未完成项，不无依据声称“全部捕获”“恰好一次”“不可变账本”或“不影响线上”。

## 12. 可独立交付的实施包

1. **审计安全**：统一脱敏、服务商安全诊断、关联编号及测试；更新现有运行指南，不改 API 行为。
2. **组件验证**：干净依赖解析、integration bundle 注册、关闭状态启动和事务／故障特性验证；不启用线上采集。
3. **操作采集**：事件格式、recorder／no-op／sink、有界上下文，以及少量 Huifu／StaffOperation／Printer 接入点，标清事务证据。
4. **查询投影**：增量迁移、可选归集、检查点及丢失／覆盖指标，不依赖资金写入。
5. **权限后台**：业务只读 API／页面、权限、分页、关联时间线、导出访问审计和保留控制。
6. **可选原生 ORM**：经审查的实体／字段清单、组件表结构及小范围灰度，单独接受其事务耦合风险后再启用。
7. **发布加固**：预发布业务等价、负载、故障和回退演练，分阶段人工批准启用。

尽量拆成独立提交／PR。支付宝功能可以不依赖此审计提案单独发布，不能把审计扩展变成未经审查的支付上线前置条件。
各功能实际完成并通过准入后才更新正式契约；本文在相应验收通过前始终是提案。

## 参考资料

- [AuditorBundle 官方文档](https://damienharper.github.io/auditor-docs/)
- [Doctrine provider 配置](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/configuration)
- [多数据库事务限制](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/multi-database)
- [AuditorBundle 稳定包信息](https://packagist.org/packages/damienharper/auditor-bundle)
- [现有分层与组合方式](architecture.md)
- [现有 API 审计运行指南](../operations/audit-logging.md)
- [汇付顾客支付契约](../contracts/huifu-customer-payments.md)
- [支付关单边界](../contracts/order-payment-closure.md)
