# ATTACK MAP

服务器攻击实时可视化

这将会帮助你以最直观的方式实时监控您的服务器受攻击情况，遵循真实的攻击速度以及攻击者所在地理位置，同时附带各个IP的攻击频率。

---
**语言**
[English](README.md)
---
**部署**

您需要将"server"文件夹部署到您的服务器，然后使用shell命令将"main.go"编译成二进制文件:

Ubuntu/Debian:

```shell
go build -o attack_monitor main.go
```

您还需要将"web"文件夹内的所有文件部署到您的网站服务器，文件"attackmap.php"可以改名为您希望的名称，比如"index.php"，另外需要注意，文件"countries.json"需要与"attackmap.php"在同一个目录下。

---

**启动**

**服务器监控脚本**
您需要在服务器上给监控脚本添加权限并且启动:

```shell
chmod +x attack_monitor
```

```shell
./attack_monitor
```
**网站页面**
还需要启动网站端，具体怎样开启网站不在此项目服务范围，不过多描述。