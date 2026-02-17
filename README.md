# BFBC2 Server Dashboard

A modern web-based management dashboard for Battlefield Bad Company 2 game servers with RCON support.

![Docker](https://img.shields.io/badge/docker-ready-brightgreen)

## Features

**Multi-Server Management**
- Manage multiple BFBC2 servers from one dashboard
- Easy server switching with tabs
- Independent settings for each server

**Server Control**
- Real-time server information
- Edit server name, description, password
- Toggle game modes (Hardcore, Friendly Fire, etc.)
- Adjust player limits and timeouts
- Round control (Next, Restart, End with winner)

**Player Management**
- Live player list with statistics
- Kick players with confirmation
- Ban players (permanent, round, or timed)
- Move players between teams
- Move players between squads

**Map Display**
- Current and next map display
- Map images for all game modes
- Round counter

**Banner Upload**
- Upload custom 512x64 PNG banners
- Live preview in dashboard

**Console**
- Send raw RCON commands
- Command history
- Real-time response display

## Quick Start

### Using Pre-built Container (Recommended)

Access at: `http://localhost:5010`
<img width="1154" height="681" alt="image" src="https://github.com/user-attachments/assets/8fd5d9c4-70c8-494e-8927-11903d79b770" />
### Using Docker Compose

1. **Create docker-compose.yml:**
```yaml
bfbc2-dashboard:
    image: ghcr.io/the-may/bfbc2-webcon:latest
    container_name: bfbc2_dashboard
    restart: unless-stopped
    network_mode: "host"
    environment:
      - RCON_HOST=127.0.0.1
      - RCON_PORTS=48888,48889 
      - RCON_PASSWORD=langaming1337
      - WEB_PORT=5010
      - TZ=Europe/Berlin
```

2. **Start the dashboard:**
```bash
docker compose up -d
```

## Configuration

### Environment Variables

| Variable | Description | Default | Example |
|----------|-------------|---------|---------|
| `RCON_HOST` | RCON server IP address | `127.0.0.1` | `192.168.1.100` |
| `RCON_PORTS` | Comma-separated RCON ports | `48888` | `48888,48889,48890` |
| `RCON_PASSWORD` | RCON admin password | `123456` | `mypassword` |
| `WEB_PORT` | Dashboard web port | `80` | `5010` |
| `TZ` | Timezone | `UTC` | `Europe/Berlin` |

### Multiple Servers

To manage multiple servers, list all RCON ports:

```bash
-e RCON_PORTS=48888,48889,48890,48891
```

Tabs will automatically appear for each server.

## Network Modes

### Host Network (Recommended, makes managing easier, feel free to mess with mapped ports and ranges)

```yaml
network_mode: "host"
environment:
  - RCON_HOST=127.0.0.1
  - WEB_PORT=5010
```

Access: `http://localhost:5010`

## Building from Source

```bash
git clone https://github.com/YOUR_USERNAME/bfbc2-dashboard.git
cd bfbc2-dashboard

docker build -t bfbc2-dashboard:local .

docker run -d \
  --name bfbc2-dashboard \
  --network host \
  -e RCON_HOST=127.0.0.1 \
  -e RCON_PORTS=48888 \
  -e RCON_PASSWORD=your_password \
  -e WEB_PORT=5010 \
  bfbc2-dashboard:local
```

## Troubleshooting

### Dashboard Shows "-" for Everything

Issue: Can't connect to RCON server

Solutions:
- Verify RCON_HOST is correct
- Verify RCON_PORTS matches your server
- Verify RCON_PASSWORD is correct
- Check RCON is enabled on game server


## Acknowledgments

- Map images from [AdKats/Procon-1](https://github.com/AdKats/Procon-1)
- BFBC2 server community
