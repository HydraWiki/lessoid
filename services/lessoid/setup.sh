#!/bin/bash
echo "Installing Node"
curl -sL https://deb.nodesource.com/setup_5.x | sudo -E bash -
sudo apt-get install -y nodejs

# might be useful to have, but probably not needed really.
#echo "Setting up N, because it's nice."
#npm install -g n

echo "Verifying permissions of lessc"
chmod +x ./less-hydra/bin/lessc

echo "setting up god directories"
mkdir pids
mkdir log
chmod 777 pids
chmod 777 log

echo "Commanding god to demonize lessc (this is probably wrong. Sysops help.)"
sudo god load lessoid.god
