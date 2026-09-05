# 员工内网企业运营中枢视觉升级需求

## Introduction

员工内网采用已确认的方案 D 作为全站视觉基线。方案 D 使用企业控制台的信息架构，并采用石墨黑、暖白和信号橙配色。升级覆盖员工工作台、六大中心、内容详情、业务表单和管理页面，同时保持现有认证、权限、接口、数据与业务行为。

## Glossary

- **运营中枢页面壳**：桌面端侧栏、移动端中心导航、全局搜索、员工身份和内容画布组成的共享界面。
- **六大中心**：制度中心、知识中心、演练中心、学习中心、业务工具和我的。
- **方案 D**：企业控制台版式与石墨黑、暖白、信号橙配色的融合设计。

## Requirements

### Requirement 1

**User Story:** AS 员工, I want 在所有内部页面使用一致的导航和视觉层级, so that 我可以快速识别当前位置并进入其他中心。

#### Acceptance Criteria

1. WHEN 员工打开加载统一认证脚本的内部页面, 运营中枢页面壳 SHALL 加载方案 D 共享样式。
2. WHILE 浏览器宽度大于 980 像素, 运营中枢页面壳 SHALL 展示石墨色左侧导航和暖白内容画布。
3. WHILE 浏览器宽度小于或等于 980 像素, 运营中枢页面壳 SHALL 展示横向中心导航并保留页面可用宽度。
4. WHEN 当前路径或业务工具锚点变化, 运营中枢页面壳 SHALL 标记唯一当前中心并设置 `aria-current="page"`。
5. WHEN 员工身份可用, 运营中枢页面壳 SHALL 展示员工名称、角色和门店。

### Requirement 2

**User Story:** AS 员工, I want 在首页看到任务、内容与常用入口, so that 我可以从一个工作台开始当天工作。

#### Acceptance Criteria

1. WHEN 员工打开内网首页, 工作台 SHALL 展示日期、员工问候、四项摘要和六大中心入口。
2. WHEN 员工使用全局搜索, 工作台 SHALL 将关键词传递到 `/search.html`。
3. WHILE 员工拥有管理权限, 工作台 SHALL 展示管理中心入口。
4. WHEN 员工选择业务工具, 工作台 SHALL 定位到业务工具区域并更新当前导航。

### Requirement 3

**User Story:** AS 员工, I want 六大中心共享一致的设计语言, so that 列表、卡片、筛选和表单具有稳定操作习惯。

#### Acceptance Criteria

1. WHILE 员工浏览内部内容, 页面 SHALL 使用石墨、暖白、信号橙设计令牌。
2. WHILE 页面展示卡片、面板或表格, 页面 SHALL 使用紧凑网格、清晰边框和统一信息层级。
3. WHEN 员工聚焦按钮、链接或输入控件, 页面 SHALL 显示可见的信号橙焦点状态。
4. WHILE 页面包含移动端业务底部导航, 运营中枢页面壳 SHALL 保留底部导航交互。

### Requirement 4

**User Story:** AS 平台维护者, I want 视觉升级保持业务合同稳定, so that 页面改造可以安全渐进上线。

#### Acceptance Criteria

1. WHEN 视觉层加载失败, 内部页面 SHALL 保留原始 DOM 和业务脚本行为。
2. WHILE 认证状态待确认, 页面 SHALL 保留现有登录恢复流程。
3. WHEN 页面使用旧版局部样式, 共享视觉层 SHALL 仅覆盖页面壳与通用组件表面。
4. WHEN 自动化测试运行, 六中心路径、移动端壳、知识中心和认证合同 SHALL 保持通过。
