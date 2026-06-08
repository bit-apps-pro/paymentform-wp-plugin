import { Result, Button } from 'antd';

export function ErrorFallback() {
  return (
    <Result
      status="error"
      title="Something went wrong"
      subTitle="An unexpected error occurred. Please try refreshing the page."
      extra={
        <Button type="primary" onClick={() => window.location.reload()}>
          Refresh
        </Button>
      }
    />
  );
}
