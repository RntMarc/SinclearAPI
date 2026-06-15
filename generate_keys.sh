#!/bin/bash

private_key=$(openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 2>/dev/null)
echo "$private_key" | sed -z 's/\n/\\n/g'
echo "$private_key" | openssl rsa -pubout 2>/dev/null | sed -z 's/\n/\\n/g'
