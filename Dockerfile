FROM node:20-alpine

WORKDIR /app

# Copy package files first for better layer caching
COPY package*.json ./

# Install production deps only
RUN npm install --production

# Copy application source
COPY . .

EXPOSE 4105

CMD ["node", "server.js"]
