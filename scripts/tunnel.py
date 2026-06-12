#!/usr/bin/env python3
"""SSH tunel k produkčnej MariaDB (c-mariadb) pre ETL import.

Číta konfiguráciu z .env projektu (TUNNEL_*). Spusti v samostatnom termináli:

    python3 scripts/tunnel.py

a nechaj bežať počas `php artisan analytics:import`. Vyžaduje balíky
sshtunnel + paramiko (sú v venv MCP servera: ~/www/mcp-servers/mysql-mcp/venv).
"""
import os
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def read_env():
    env = {}
    with open(os.path.join(ROOT, ".env")) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k] = v.strip().strip('"')
    return env


def main():
    env = read_env()
    try:
        from sshtunnel import SSHTunnelForwarder
    except ImportError:
        venv_py = os.path.expanduser("~/www/mcp-servers/mysql-mcp/venv/bin/python3")
        if os.path.exists(venv_py) and sys.executable != venv_py:
            os.execv(venv_py, [venv_py] + sys.argv)
        print("Chýba balík sshtunnel: pip install sshtunnel")
        sys.exit(1)

    local_port = int(env.get("TUNNEL_LOCAL_PORT", "3307"))
    tunnel = SSHTunnelForwarder(
        (env["TUNNEL_SSH_HOST"], int(env.get("TUNNEL_SSH_PORT", "22"))),
        ssh_username=env["TUNNEL_SSH_USER"],
        ssh_pkey=env["TUNNEL_SSH_KEY"],
        ssh_private_key_password=env.get("TUNNEL_SSH_KEY_PASSPHRASE") or None,
        remote_bind_address=(env.get("TUNNEL_REMOTE_HOST", "c-mariadb"), 3306),
        local_bind_address=("127.0.0.1", local_port),
    )
    tunnel.start()
    print(f"Tunel beží: 127.0.0.1:{local_port} -> {env.get('TUNNEL_REMOTE_HOST', 'c-mariadb')}:3306 (Ctrl+C ukončí)")
    try:
        while True:
            time.sleep(60)
    except KeyboardInterrupt:
        pass
    finally:
        tunnel.stop()
        print("Tunel ukončený.")


if __name__ == "__main__":
    main()
