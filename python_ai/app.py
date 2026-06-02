from flask import Flask
from flask_cors import CORS
import logging

from api_routes import api

app = Flask(__name__)

CORS(app)

logging.basicConfig(level=logging.INFO)

logger = logging.getLogger(__name__)

app.register_blueprint(api)

if __name__ == "__main__":

    app.run(
        host="127.0.0.1",
        port=8000,
        debug=False
    )