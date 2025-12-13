# Storage Node Graphs

A Docker-based monitoring solution for Storj storage nodes that collects disk space and expected earnings data, stores it in an RRD database, and displays historical graphs via a web interface.

## Features

- **Automatic Data Collection**: Polls multiple Storj storage nodes every 5 minutes
- **RRD Database**: Stores historical data with multiple retention policies (1 day, 1 week, 1 year)
- **Visual Graphs**: Generates PNG graphs showing disk space usage and expected earnings over time with dark theme
- **Web Interface**: Simple HTML page with black background and auto-refresh every minute
- **Docker-Based**: Easy deployment using Docker Compose
- **Configurable**: Environment variables for nodes, graph history, and graph dimensions

## Requirements

- Docker and Docker Compose
- Access to Storj storage node APIs (default port 14002)
- Network connectivity to your storage nodes

## Quick Start

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd StorageNodeGraphs
   ```

2. **Configure your storage nodes**:
   
   Edit `compose.yaml` and set the environment variables:
   ```yaml
   environment:
     - STORAGE_NODES=node1:14002,node2:14002,node3:14002
     - GRAPH_HISTORY=60d
     - GRAPH_WIDTH=1200
     - GRAPH_HEIGHT=600
   ```
   
   Or create a `compose.override.yaml` file (recommended):
   ```yaml
   services:
     storage-node-graphs:
       environment:
         - STORAGE_NODES=node1:14002,node2:14002,node3:14002
   ```

3. **Start the container**:
   ```bash
   docker compose up -d
   ```

4. **Access the web interface**:
   
   Open your browser and navigate to `http://localhost:8080`

## Configuration

### Environment Variables

| Variable | Description | Default | Example |
|----------|-------------|---------|---------|
| `STORAGE_NODES` | Comma-separated list of storage node addresses | Required | `node1:14002,node2:14002` |
| `GRAPH_HISTORY` | Time period for graph display (minus sign added automatically) | `5weeks` | `60d`, `1y`, `720h` |
| `GRAPH_WIDTH` | Graph width in pixels | `1200` | `900`, `1600` |
| `GRAPH_HEIGHT` | Graph height in pixels | `600` | `400`, `800` |

### Graph History Format

The `GRAPH_HISTORY` variable accepts RRDtool time formats. **Note**: The minus sign is automatically added, so specify the value without it:
- `60d` - 60 days
- `5weeks` - 5 weeks
- `1y` - 1 year
- `720h` - 720 hours

### Storage Nodes Format

Nodes should be specified as `hostname:port` or `ip:port`, separated by commas:
```
STORAGE_NODES=ssdstation:14002,cubi:14002,terramaster:14002
```

## Data Collection

The collector script (`collector.php`) runs automatically every 5 minutes and:

1. Queries each storage node's API endpoints:
   - `/api/sno/` - Disk space information
   - `/api/sno/estimated-payout` - Expected earnings

2. Aggregates data from all nodes:
   - Total disk space available
   - Total disk space used
   - Total expected monthly earnings

3. Updates the RRD database (`/data/db.rrd`)

4. Generates a new graph (`graph.png`)

## Data Storage

The RRD database is stored in the `./data` directory (mounted as a volume) and persists across container restarts. The database includes:

- **Data Sources (DS)**:
  - `diskAvail` - Available disk space (TB)
  - `diskUsed` - Used disk space (TB)
  - `monthExpect` - Expected monthly earnings (USD)

- **Round Robin Archives (RRA)**:
  - 1-day resolution (5-minute intervals)
  - 1-week resolution (1-hour intervals)
  - 1-year resolution (1-day intervals)

## File Structure

```
StorageNodeGraphs/
├── collector.php          # Data collection script
├── index.php              # Web interface
├── entrypoint.sh          # Container startup script
├── Dockerfile             # Container image definition
├── compose.yaml           # Docker Compose configuration
├── compose.override.yaml  # User-specific overrides (gitignored)
├── favicon.png            # Website favicon
├── data/                  # RRD database storage (gitignored)
│   └── db.rrd
└── README.md              # This file
```

## Web Interface

The web interface (`index.php`) displays:
- A graph showing disk space and earnings trends with dark theme (black background)
- Auto-refresh every 60 seconds
- Current values displayed on the graph
- Black background matching the graph theme

Access it at `http://localhost:8080` (or your configured port).

### Graph Appearance

The graphs feature a dark theme with:
- Black background and canvas
- White text for labels and values
- Dark gray grid lines for subtle visibility
- Colorful data lines (green for available space, blue for used space, orange for earnings)

## Troubleshooting

### Container won't start

- Check that `STORAGE_NODES` environment variable is set
- Verify Docker and Docker Compose are installed and running
- Check container logs: `docker compose logs`

### No data in graph

- Ensure storage nodes are accessible from the container
- Check that nodes are responding on port 14002
- Verify the API endpoints are correct
- Check collector logs: `docker compose logs storage-node-graphs`

### Graph not updating

- The collector runs every 5 minutes
- Wait at least 5 minutes after starting the container
- Check that the RRD database is being updated
- Verify file permissions on the `./data` directory

### RRD database errors

- Ensure the `./data` directory exists and is writable
- Check disk space availability
- Review collector logs for specific error messages

## Development

### Building the image manually

```bash
docker build -t storage-node-graphs .
```

### Running the collector manually

```bash
docker compose run --rm storage-node-graphs php /var/www/html/collector.php
```

### Viewing logs

```bash
docker compose logs -f storage-node-graphs
```
