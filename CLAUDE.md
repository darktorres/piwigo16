# Piwigo Fork — Claude Notes

## MySQL

Binary: `"C:/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe"`  
Database: `piwigo_fork`  
User: `root`  
Password: `1234`  
Host: `127.0.0.1`

```bash
"/c/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe" -u root -p1234 -h 127.0.0.1 piwigo_fork -e "YOUR SQL HERE"
```

## Package Manager

This project uses **bun**, not npm or yarn.

- Run tests: `bun test`
- Run lint: `bun run lint`
- Build: `bun run build`
- Install dependencies: `bun install`
