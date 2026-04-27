# M3U8 代理服务（PHP + Apache）

这是一个轻量的 M3U8 代理，用于：

- 透传远端媒体资源（m3u8 / ts / 其他片段文件）
- 重写 m3u8 内部相对路径为当前代理地址
- 按环境变量设置 `Referer` / `User-Agent`

## 快速部署

### 方式 1：Docker 本地部署

```bash
docker build -t m3u8-proxy .
docker run --rm -p 8080:80 \
  -e TARGET_REFERER=none \
  -e TARGET_UA="Mozilla/5.0 ..." \
  -e ENABLE_HOST_ALLOWLIST=true \
  -e TARGET_HOST_ALLOWLIST="example.com,cdn.example.com" \
  m3u8-proxy
```

访问示例：

```text
http://localhost:8080/index.php?url=https%3A%2F%2Fexample.com%2Flive%2Findex.m3u8
```

### 方式 2：Zeabur / Railway / Render

1. 将仓库导入平台并使用 `Dockerfile` 构建。
2. 设置环境变量（见下文）。
3. 暴露容器 80 端口（平台通常自动处理）。
4. 使用平台分配域名访问 `index.php?url=...`。


### 方式 3：Vercel 部署

1. 将仓库导入 Vercel。
2. 保留仓库内 `vercel.json`（已配置 `@vercel/php` 运行时并将所有路由转发到 `index.php`）。
3. 在 Vercel Project Settings -> Environment Variables 中配置本项目环境变量。
4. 部署后通过：`https://你的域名/index.php?url=...` 访问。

> 说明：Vercel 是 Serverless 模式，请注意单次执行时长与流媒体大文件转发限制，建议用于轻量场景。

## 环境变量

- `TARGET_REFERER`：
  - `none` 或留空：不携带 Referer
  - 其他值：向上游携带该 Referer
- `TARGET_UA`：请求上游时使用的 UA
- `ENABLE_HOST_ALLOWLIST`：是否启用白名单限制，默认 `false`
- `TARGET_HOST_ALLOWLIST`：允许代理的目标域名白名单（逗号分隔，仅在 `ENABLE_HOST_ALLOWLIST=true` 时生效）
- `INSECURE_SSL`：`true/1` 时关闭 SSL 证书校验（不推荐线上开启）
- `CONNECT_TIMEOUT`：连接超时秒数，默认 `5`
- `REQUEST_TIMEOUT`：总请求超时秒数，默认 `20`

## 我做的优化（已实现）

1. **安全性**
   - 限制只支持 `http/https`
   - 白名单可按需开启（`ENABLE_HOST_ALLOWLIST=true`），开启后用 `TARGET_HOST_ALLOWLIST` 限制目标域
   - 默认启用 SSL 校验，仅在 `INSECURE_SSL=true` 时关闭

2. **稳定性**
   - 增加连接超时与请求超时
   - 上游失败返回明确错误码/错误信息

3. **兼容性**
   - m3u8 重写新增对 `../`、`./`、`//cdn...` 等路径的处理

## 进一步可做的优化建议

- 将 ts/分片改为**流式转发**（减少大文件内存占用）
- 引入 Nginx 或 CDN 缓存热点分片
- 对上游异常增加重试与熔断策略
- 加访问日志与请求 ID，便于排查卡顿/失败

## 注意事项

- 本项目仅用于合规场景，请确保你有权访问和转发目标流媒体内容。
- 生产环境建议开启 HTTPS，并通过白名单严格限制可代理域名。
