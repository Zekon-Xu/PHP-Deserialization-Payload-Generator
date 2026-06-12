# PHPGGC 集成产品化路线

## 目标

把当前离线 HTML 工具升级为一个本地应用：

> PHP 反序列化分析工作台 = 手工构造/修复/编解码 + PHPGGC gadget chain 查询/生成 + 授权测试辅助。

官方项目名为 PHPGGC。它是 PHP `unserialize()` payload 库和生成工具，使用 Apache-2.0 许可证。集成时应保留原项目许可证和版权声明。

## 推荐实现

优先走“本地 Web 应用”：

- 前端继续复用当前 HTML/JS 工具。
- 后端使用 Python FastAPI 或 Flask。
- 后端调用本机 PHP CLI 和 `vendor/phpggc/phpggc`。
- 所有 payload 只在本地生成，不自动向目标发送请求。

## MVP 功能

1. 环境检测：PHP CLI、PHPGGC 路径、PHPGGC 许可证。
2. Chain 列表：解析 `phpggc -l` 输出，按框架/类型/关键词筛选。
3. Chain 详情：解析 `phpggc -i <chain>` 输出，展示参数说明。
4. Payload 生成：表单填写参数，调用 PHPGGC 生成 payload。
5. 后处理：回填现有输出区，继续做 URL/Base64 编码、长度修复和 query 拼接。
6. 安全边界：授权提示、危险 chain 提醒、本地审计日志、不自动发包。

## 目录建议

```text
PHP反序列化Payload构造工具/
  app/
    frontend/
    backend/
  vendor/
    phpggc/
  runtime/
    logs/
  scripts/
    run_app.bat
    check_env.bat
  PHP反序列化Payload构造工具.html
  README.md
```

## 简历卖点

- 不是只会使用 PHPGGC，而是理解 PHP 序列化格式并能做工具化封装。
- 当前工具解决手工构造、解析、长度修正和编解码问题。
- 集成 PHPGGC 后补齐成熟 gadget chain 查询与生成能力。
- 产品设计保留授权提示、离线运行、审计日志和不自动发包的安全边界。

## 边界

- 只用于 CTF、授权靶场、教学和防御研究。
- 不写入真实目标扫描功能。
- 不内置批量攻击流程。
- 不在简历中表述为“通用 EXP 自动化攻击平台”。
