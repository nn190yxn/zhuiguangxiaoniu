# 员工内网设计方向预览

Feature Name: intranet-design-preview  
Updated: 2026-09-04

## Description

新增独立静态预览页，使用相同业务内容模拟三种原始视觉系统及一套融合方案。融合方案复用企业控制台结构，并应用石墨、暖白和信号橙设计令牌。预览与现有认证、接口和业务页面隔离，视觉方向确定后再进入正式页面改造。

## Components and Interfaces

- `design-preview.html`：三套工作台语义结构和预览控制栏。
- `assets/intranet-design-preview.css`：三套独立主题及桌面、平板、移动端布局。
- `js/intranet-design-preview.js`：方案切换、键盘导航、URL hash 和本地首选标记。

## Correctness Properties

1. 三个切换按钮分别对应一个预览区域。
2. 任意时刻只展示一个预览区域。
3. 方向键切换在三个方案间循环。
4. 首选记录只保存方案标识，不包含业务数据。

## Error Handling

- 浏览器禁用本地存储时，方案切换继续可用。
- URL hash 无法匹配方案时，默认展示方案 A。
- 窄屏设备使用响应式重排维持内容可读性。

## Test Strategy

- 静态契约测试验证三套方案、交互脚本和响应式断点。
- JavaScript 语法检查验证预览脚本。
- 本地预览验证页面及静态资源返回 HTTP 200。
