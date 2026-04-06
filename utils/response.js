/**
 * Standard response formatter
 */

const success = (res, data, message = 'Success', statusCode = 200) => {
  return res.status(statusCode).json({
    success: true,
    message,
    data
  });
};

const error = (res, message = 'Error', statusCode = 500, errorCode = null, details = null) => {
  const response = {
    success: false,
    message
  };

  if (errorCode) response.errorCode = errorCode;
  if (details) response.details = details;

  return res.status(statusCode).json(response);
};

const validationError = (res, errors) => {
  return res.status(400).json({
    success: false,
    message: 'Validation failed',
    errors
  });
};

const notFound = (res, message = 'Resource not found') => {
  return res.status(404).json({
    success: false,
    message
  });
};

const unauthorized = (res, message = 'Unauthorized') => {
  return res.status(401).json({
    success: false,
    message
  });
};

const forbidden = (res, message = 'Forbidden') => {
  return res.status(403).json({
    success: false,
    message
  });
};

const conflict = (res, message = 'Conflict') => {
  return res.status(409).json({
    success: false,
    message
  });
};

module.exports = {
  success,
  error,
  validationError,
  notFound,
  unauthorized,
  forbidden,
  conflict
};
