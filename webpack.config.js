const path = require('path');

module.exports = {
    mode: 'production',
    entry: './src/blink-block.js', // Entry file path relative to context
    output: {
        path: path.resolve(__dirname, 'dist'),
        filename: 'blink-block.js',
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: [
                            "@babel/preset-env",
                            "@babel/preset-react",
                            "@babel/preset-typescript"
                        ],
                    },
                },
            },
        ],
    },
    resolve: {
        modules: [path.resolve(__dirname, 'node_modules'), 'node_modules']
    }
};
