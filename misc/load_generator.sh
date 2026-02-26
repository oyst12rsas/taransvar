#!/bin/bash

# Function to generate load for a specific duration
generate_load() {
    local duration=$1
    echo "Starting stress test for ${duration} seconds..."
    # Run stress to fully utilize all CPU cores for the specified duration
    stress --cpu "$(nproc)" --timeout "$duration" &> /dev/null
    echo "Stress test for ${duration} seconds completed."
}

# Generate load for 1 minute, 5 minutes, and 15 minutes sequentially
generate_load 60    # 1 minute
sleep 10            # Cooldown period
generate_load 300   # 5 minutes
sleep 10            # Cooldown period
generate_load 900   # 15 minutes

echo "All stress tests completed."

