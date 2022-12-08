#!/bin/sh

OS=$(uname -s)

if [ "$OS" = "Linux" ]; then
    ethtool --offload $1 rx off tx off
    ethtool -K $1 sg off
    ethtool -K $1 gro off
    ethtool -K $1 gso off
    ifconfig $1 up
    ifconfig $1 promisc
fi
