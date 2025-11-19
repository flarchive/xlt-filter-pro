# XLT FilterPro

[![GitHub](https://img.shields.io/badge/GitHub-XiaoLuoTian189%2Ffilter--pro-blue)](https://github.com/XiaoLuoTian189/filter-pro)

一个功能强大的 Flarum 内容过滤插件，基于 `fof/filter` 和 `hamcq/filter-plus` 修复二改而来。

**GitHub 仓库**: [https://github.com/XiaoLuoTian189/filter-pro](https://github.com/XiaoLuoTian189/filter-pro)

## 功能特性

### 🔍 双重内容检测机制

1. **本地敏感词过滤**
   - 支持自定义敏感词列表
   - 智能识别 Leet 语变体（如 1337 语）
   - 自动标记包含敏感词的帖子

2. **阿里云内容安全检测**（可选）
   - 集成阿里云内容安全服务
   - 支持长文本分段检测（超过 600 字符自动分段）
   - 可配置跳过特定标签
   - 检测失败时自动降级到本地敏感词检测

### 🛡️ 自动审核功能

- 自动标记违规帖子，需要管理员审核
- 支持自动标记讨论主题（首帖）
- 可配置邮件通知用户
- 记录详细的检测日志

### 🔄 自动合并功能

- 自动合并同一用户在短时间内发布的重复帖子
- 可配置合并时间间隔（默认 15 分钟）

### 📊 检测日志

- 记录所有阿里云检测结果
- 包含用户 ID、讨论 ID、帖子 ID 和检测结果
- 便于管理员追溯和审计

## 安装

### 使用 Composer

```bash
composer require xlt/filter-pro
```

### 启用插件

在 Flarum 管理后台启用 "XLT FilterPro" 插件。

## 配置

### 基本设置

1. **敏感词列表**
   - 在管理后台的 "Filter" 设置页面
   - 每行输入一个敏感词或短语
   - 支持正则表达式

2. **自动合并设置**
   - 启用/禁用自动合并功能
   - 设置合并时间间隔（分钟）

3. **邮件通知设置**
   - 启用/禁用邮件通知
   - 自定义邮件主题和内容

### 阿里云内容安全配置

1. **启用阿里云检测**
   - 在设置中启用 "阿里云内容检测"

2. **配置访问凭证**
   - `access_id`: 阿里云 AccessKey ID
   - `access_sec`: 阿里云 AccessKey Secret
   - `skip_label`: 需要跳过的标签（逗号分隔）

3. **获取阿里云凭证**
   - 登录阿里云控制台
   - 创建 AccessKey（建议使用子账号并授予最小权限）
   - 开通内容安全服务

## 权限设置

- **Bypass word filter(s)**: 允许特定用户组绕过敏感词过滤

## 工作原理

### 检测流程

1. 用户发布帖子时触发检测
2. 如果启用了阿里云检测：
   - 优先使用阿里云内容安全服务检测
   - 如果检测通过（无违规），跳过本地检测
   - 如果检测失败或未启用，执行本地敏感词检测
3. 检测到违规内容时：
   - 自动标记帖子为待审核
   - 创建自动标记记录
   - 可选发送邮件通知用户
   - 记录检测日志（仅阿里云检测）

### 长文本处理

- 阿里云接口限制单次检测最大 600 字符
- 超过限制的文本会自动分段检测
- 任何一段检测到违规都会标记整个帖子

### Leet 语识别

插件能够识别常见的字符替换变体，例如：
- `a` → `4`, `@`, `á`, `à` 等
- `e` → `3`, `€`, `è`, `é` 等
- `i` → `1`, `!`, `|`, `í` 等
- 更多变体请参考代码实现

## 数据库迁移

插件包含以下数据库迁移：

- `2017_03_08_102708_add_emailed_to_posts.php` - 添加邮件标记字段
- `2019_07_05_022521_add_auto_mod_to_posts.php` - 添加自动审核标记字段
- `2021_09_12_modify_badwords_delimiter.php` - 修改敏感词分隔符
- `2023_08_25_created_content_check_log.php` - 创建内容检测日志表

## 技术栈

- **后端**: PHP (Flarum Extension)
- **前端**: TypeScript + Webpack
- **依赖**:
  - `flarum/core`: ^1.2.0
  - `flarum/approval`: 审核扩展
  - `flarum/flags`: 标记扩展
  - `alibabacloud/green-20220302`: 阿里云内容安全 SDK

## 开发

### 构建前端资源

```bash
cd js
npm install
npm run build
```

### 开发模式

```bash
cd js
npm run dev
```

## 注意事项

1. **性能考虑**
   - 阿里云检测需要网络请求，可能增加响应时间
   - 建议在生产环境使用，并配置合适的超时时间

2. **隐私保护**
   - 阿里云检测会将内容发送到第三方服务
   - 请确保符合相关隐私法规要求

3. **错误处理**
   - 阿里云检测失败时会自动降级到本地检测
   - 所有错误都会记录到日志中

4. **点赞和反应**
   - 插件已优化，点赞和反应不会触发重新检测

## 更新日志

### 基于原版的改进

- ✅ 修复点赞触发重新检测的问题
- ✅ 优化反应处理逻辑
- ✅ 增强阿里云检测的错误处理
- ✅ 添加长文本分段检测支持
- ✅ 添加检测日志记录功能
- ✅ 改进代码结构和可维护性

## 许可证

MIT License

## 致谢

- [fof/filter](https://github.com/FriendsOfFlarum/filter) - 原始过滤器插件
- [hamcq/filter-plus](https://github.com/hamcq/filter-plus) - 增强版过滤器插件
- 所有贡献者和用户

## 支持

如有问题或建议，请访问 [GitHub 仓库](https://github.com/XiaoLuoTian189/filter-pro) 提交 Issue 或 Pull Request。

---

**注意**: 本插件基于 `fof/filter` 和 `hamcq/filter-plus` 修复二改而来，保留了原有功能并进行了优化和增强。

