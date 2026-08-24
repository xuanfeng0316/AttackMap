# ATTACK MAP

Real-time visualization of server attacks

This will help you monitor the attack status of your server in the most intuitive way in real time, following the real attack speed and the geographic location of attackers, along with the attack frequency of each IP.

---
**Language**
[中文](README.zh.md)
---

**Deployment**

You need to deploy the "server" folder to your server, then use shell commands to compile "main.go" into a binary file:

Ubuntu/Debian:

```
go build -o attack_monitor main.go
```

You also need to deploy all files in the "web" folder to your web server. The file "attackmap.php" can be renamed to whatever you prefer, for example "index.php". Also note that the file "countries.json" must be in the same directory as "attackmap.php".

---

**Startup**

**Server monitoring script**
You need to grant permissions to the monitoring script on the server and start it:

```
chmod +x attack_monitor
```

```
./attack_monitor
```

**Web page**
You also need to start the web server. How to start the website is beyond the scope of this project and will not be described here.