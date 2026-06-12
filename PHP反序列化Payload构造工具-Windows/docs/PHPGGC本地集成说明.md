# PHPGGC 本地集成说明

## 定位

本项目将原本的离线 HTML/JavaScript PHP 反序列化 Payload 构造工具，扩展为本地离线工作台：

```text
手工构造 / 解析修复 / 编解码
+ PHPGGC gadget chain 查询
+ PHPGGC payload 本地生成
+ 请求草稿生成
+ 本地脱敏审计摘要
```

## 官方来源

PHPGGC 官方仓库：

```text
https://github.com/ambionics/phpggc
```

PHPGGC 使用 Apache-2.0 License。集成时保留 `vendor/phpggc/LICENSE`。

## 本地封装目录

```text
PHP反序列化Payload构造工具/
  PHP反序列化Payload构造工具.html
  app/backend/
    router.php
    phpggc_runner.php
  runtime/php/
    php.exe
    php.ini
    ext/
    *.dll
  runtime/logs/
    phpggc_audit.log
  scripts/
    run_app.bat
    run_app.ps1
    check_env.bat
  vendor/phpggc/
    phpggc
    gadgetchains/
    lib/
    templates/
    LICENSE
```

## 运行方式

双击：

```bat
双击我启动工具.bat
```

脚本会自动选择端口并打开浏览器。默认从 `8765` 开始，如果被占用会尝试 `8766`、`8767`，直到 `8799`。启动窗口会输出本次访问链接，并生成 `Open-PHPGGC-Workbench.url`。正常关闭服务时会自动清理这个 `.url` 文件；异常断电或强制结束导致残留时，下次启动会覆盖成新的正确链接。

网页右上角提供「启动服务」「重启服务」「关闭服务」按钮：

- 「启动服务」只做检测和引导。浏览器不能直接执行本地 `.bat` 或 `.exe`，因此未启动服务时仍需双击 `双击我启动工具.bat`。
- 「重启服务」会弹窗确认，确认后调用本机 `/api/restart`。后端会删除旧的 `Open-PHPGGC-Workbench.url` 并写入 `runtime/restart.request`，启动脚本检测到该标记后停止当前 PHP 内置 Web Server、重新选择端口、重写 `.url` 并打开新页面。
- 「关闭服务」会弹窗确认，确认后调用本机 `/api/shutdown`。后端会删除 `Open-PHPGGC-Workbench.url` 并写入 `runtime/shutdown.request`，启动脚本检测到该标记后关闭当前 PHP 内置 Web Server 并清理标记文件。

也可以手动访问启动窗口中的链接，例如：

```text
http://127.0.0.1:8765/
```

环境检查：

```bat
scripts\check_env.bat
```

## 接口

| 接口 | 作用 |
| --- | --- |
| `GET /api/env` | 检测内置 PHP CLI、PHPGGC 和许可证路径 |
| `GET /api/chains` | 解析 `phpggc -l`，返回 chain 列表 |
| `GET /api/chains/{name}` | 调用 `phpggc -i <chain>`，返回详情与参数 |
| `POST /api/generate` | 本地生成 payload |
| `GET /api/audit` | 返回脱敏审计摘要 |
| `POST /api/shutdown` | 同源确认后关闭当前本地服务 |
| `POST /api/restart` | 同源确认后重启当前本地服务 |

## 安全边界

- 只用于授权测试、CTF、实验室、教学和防御研究。
- 只在本机生成 payload。
- 不提供公网扫描能力。
- 不自动发送请求。
- 不提供批量攻击流程。
- 请求草稿功能只生成 GET/POST/curl/Burp 文本。
- 审计日志只记录 chain、参数长度、payload 哈希和字节数，不保存明文参数。

## 特别鸣谢

特别鸣谢 PHPGGC 项目的开源贡献，感谢 PHPGGC 的开发人员和维护者为 PHP 反序列化研究、教学和防御验证提供成熟的 gadget chain 工具库。
